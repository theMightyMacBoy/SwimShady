<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\HistoryEntry;
use App\Models\IpRule;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecurityAndWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_logins_ban_unless_whitelisted(): void
    {
        config([
            'swimshady.ban_threshold' => 2,
            'swimshady.ban_window_seconds' => 60,
        ]);

        $ip = '203.0.113.50';
        $this->fromIp($ip)->post('/login', ['username' => 'nope', 'password' => 'nope']);
        $this->fromIp($ip)->post('/login', ['username' => 'nope', 'password' => 'nope']);
        $this->fromIp($ip)->post('/login', ['username' => 'nope', 'password' => 'nope'])
            ->assertSessionHas('error', 'Your IP is temporarily blocked.');

        IpRule::query()->create([
            'ip' => $ip,
            'kind' => IpRule::ALLOW,
            'source' => IpRule::MANUAL,
            'reason' => 'home',
        ]);
        $this->fromIp($ip)->post('/login', ['username' => 'nope', 'password' => 'nope'])
            ->assertSessionHas('error', 'Invalid username or password.');
    }

    public function test_manual_deny_blocks_the_device_api(): void
    {
        [$device, $token] = $this->makeDevice();
        IpRule::query()->create([
            'ip' => '198.51.100.20',
            'kind' => IpRule::DENY,
            'source' => IpRule::MANUAL,
        ]);

        $this->fromIp('198.51.100.20')
            ->withHeader('X-Device-Token', $token)
            ->postJson('/api/device/heartbeat', ['water_temp' => 70])
            ->assertForbidden()
            ->assertJsonPath('error', 'your IP is temporarily blocked');

        $this->assertNull($device->fresh()->last_seen_at);
    }

    public function test_prune_keeps_manual_rules(): void
    {
        config(['swimshady.history_retention_days' => 30, 'swimshady.log_retention_days' => 30]);
        $device = $this->makeDevice()[0];
        HistoryEntry::query()->create([
            'device_id' => $device->id,
            'recorded_at' => now()->subDays(40),
            'water_temp' => 70,
        ]);
        HistoryEntry::query()->create([
            'device_id' => $device->id,
            'recorded_at' => now()->subDay(),
            'water_temp' => 71,
        ]);
        $attempt = new LoginAttempt(['ip' => '1.1.1.1', 'success' => false]);
        $attempt->created_at = now()->subDays(40);
        $attempt->save();
        $auto = new IpRule([
            'ip' => '1.1.1.1',
            'kind' => IpRule::DENY,
            'source' => IpRule::AUTO,
            'expires_at' => now()->subDays(39),
        ]);
        $auto->created_at = now()->subDays(40);
        $auto->save();
        $manual = new IpRule([
            'ip' => '8.8.8.8',
            'kind' => IpRule::ALLOW,
            'source' => IpRule::MANUAL,
        ]);
        $manual->created_at = now()->subDays(40);
        $manual->save();

        $this->artisan('swimshady:prune')->assertSuccessful();
        $this->assertSame(1, HistoryEntry::query()->count());
        $this->assertSame(0, LoginAttempt::query()->count());
        $this->assertSame(1, IpRule::query()->count());
        $this->assertSame('8.8.8.8', IpRule::query()->first()->ip);
    }

    public function test_seed_is_idempotent_and_demo_can_drive_the_dashboard(): void
    {
        $this->seed();
        $admin = User::query()->where('username', 'admin')->first();
        $admin->password = 'replaced-password';
        $admin->save();
        $this->seed();
        $this->assertSame(1, User::query()->where('username', 'admin')->count());
        $this->assertSame(1, User::query()->where('username', 'demo')->count());
        $this->assertTrue(Hash::check('replaced-password', $admin->fresh()->password));

        $this->post('/login', ['username' => 'demo', 'password' => 'demo'])->assertRedirect('/');
        $this->get('/')->assertOk()->assertSee('Water Temperature')->assertSee('Demo Pool');
        $this->get('/devices')->assertForbidden();

        $id = Device::query()->where('is_demo', true)->value('id');
        $this->post('/api/ui/command', ['device' => $id, 'action' => 'pump_on'])->assertOk();
        $this->getJson('/api/ui/state?device='.$id)
            ->assertOk()
            ->assertJsonPath('pumpOn', true)
            ->assertJsonPath('online', true);
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-For', $ip);
    }
}
