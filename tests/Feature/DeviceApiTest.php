<?php

namespace Tests\Feature;

use App\Models\Command;
use App\Models\Device;
use App\Models\Firmware;
use App\Models\HistoryEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeviceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_stores_state_and_hands_each_command_out_once(): void
    {
        [$device, $token] = $this->makeDevice();
        Command::query()->create([
            'device_id' => $device->id,
            'action' => 'set_target',
            'value' => '84',
            'status' => Command::PENDING,
        ]);

        $first = $this->withHeader('X-Device-Token', $token)->postJson('/api/device/heartbeat', [
            'water_temp' => 78.4,
            'target_temp' => 82,
            'pump_on' => true,
            'system_on' => true,
            'heater_on' => false,
            'heater_status' => 'OFF',
            'pump_status' => 'ON',
            'cooldown_remaining' => '--:--',
            'firmware_version' => '2026.06.08.4',
        ]);

        $first->assertOk();
        $this->assertSame((string) strlen($first->getContent()), $first->headers->get('Content-Length'));
        $first->assertJsonPath('ok', true);
        $first->assertJsonPath('commands.0.action', 'set_target');
        $first->assertJsonPath('commands.0.value', '84');
        $first->assertJsonPath('update', null);
        $this->assertNotEmpty($first->json('serverTime'));

        $device->refresh();
        $this->assertSame('2026.06.08.4', $device->firmware_version);
        $this->assertEquals(78.4, $device->state->water_temp);
        $this->assertTrue($device->state->pump_on);
        $this->assertSame(0, Command::query()->where('status', Command::PENDING)->count());
        $this->assertSame(1, HistoryEntry::query()->count());

        $second = $this->withHeader('X-Device-Token', $token)->postJson('/api/device/heartbeat', [
            'water_temp' => 78.4,
            'target_temp' => 84,
            'pump_on' => true,
            'system_on' => true,
            'heater_on' => false,
            'firmware_version' => '2026.06.08.4',
        ]);
        $second->assertJsonPath('commands', []);
        $this->assertSame(1, HistoryEntry::query()->count());
    }

    public function test_boot_state_and_rejected_token(): void
    {
        [$device, $token] = $this->makeDevice();
        $this->postJson('/api/device/heartbeat')->assertUnauthorized();
        $this->withHeader('X-Device-Token', 'nope')->postJson('/api/device/heartbeat', [])->assertUnauthorized();

        $this->withHeader('X-Device-Token', $token)->postJson('/api/device/heartbeat', [
            'target_temp' => 88,
            'system_on' => true,
            'pump_on' => false,
            'water_temp' => 75,
        ])->assertOk();

        $state = $this->withHeader('X-Device-Token', $token)->get('/api/device/state');
        $state->assertOk();
        $this->assertSame((string) strlen($state->getContent()), $state->headers->get('Content-Length'));
        $state->assertExactJson([
            'hasState' => true,
            'targetTemp' => 88,
            'systemOn' => true,
            'pumpOn' => false,
        ]);
    }

    public function test_firmware_offer_is_per_channel_and_download_uses_the_query_token(): void
    {
        Storage::fake('local');
        [$device, $token] = $this->makeDevice(['channel' => Device::STABLE]);
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->post('/firmware', [
            'version' => '2026.10.25.1',
            'channel' => 'dev',
            'file' => UploadedFile::fake()->createWithContent('dev.bin', 'dev-bytes'),
        ])->assertRedirect();

        $stable = $this->actingAs($admin)->post('/firmware', [
            'version' => '2026.10.25.2',
            'channel' => 'stable',
            'notes' => 'heater tweak',
            'file' => UploadedFile::fake()->createWithContent('stable.bin', 'stable-bytes'),
        ]);
        $stable->assertRedirect();

        $heartbeat = $this->withHeader('X-Device-Token', $token)->postJson('/api/device/heartbeat', [
            'firmware_version' => '2026.06.08.4',
            'water_temp' => 75,
        ]);
        $heartbeat->assertOk();
        $update = $heartbeat->json('update');
        $this->assertSame('2026.10.25.2', $update['version']);
        $this->assertStringContainsString('/api/device/firmware/bin?token='.$token, $update['url']);
        $this->assertStringContainsString('/api/device/firmware/md5?token='.$token, $update['md5Url']);

        $same = $this->withHeader('X-Device-Token', $token)->postJson('/api/device/heartbeat', [
            'firmware_version' => '2026.10.25.2',
        ]);
        $same->assertJsonPath('update', null);

        $md5 = $this->get('/api/device/firmware/md5?token='.$token);
        $md5->assertOk();
        $this->assertStringStartsWith('text/plain', (string) $md5->headers->get('Content-Type'));
        $this->assertSame(md5('stable-bytes'), $md5->getContent());
        $this->assertSame((string) strlen(md5('stable-bytes')), $md5->headers->get('Content-Length'));

        $bin = $this->get('/api/device/firmware/bin?token='.$token);
        $bin->assertOk();
        $this->assertSame('stable-bytes', $bin->streamedContent());

        $this->get('/api/device/firmware/bin?token=nope')->assertUnauthorized();

        $firmware = Firmware::query()->where('channel', 'stable')->first();
        $this->actingAs($admin)->post('/firmware/'.$firmware->id.'/delete')->assertRedirect();
        $this->assertSame(0, Firmware::query()->where('channel', 'stable')->count());
    }
}
