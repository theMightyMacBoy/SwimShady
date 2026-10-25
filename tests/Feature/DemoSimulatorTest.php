<?php

namespace Tests\Feature;

use App\Models\Command;
use App\Models\Device;
use App\Services\DemoSimulator;
use App\Services\DeviceTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DemoSimulatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_heats_locks_out_and_cools_down(): void
    {
        $start = Carbon::parse('2026-06-01 15:00:00', 'UTC');
        Carbon::setTestNow($start);

        $device = Device::query()->create([
            'uid' => 'demo-pool',
            'name' => 'Demo Pool',
            'is_demo' => true,
            'token_hash' => DeviceTokens::hash(DeviceTokens::new()),
            'channel' => Device::STABLE,
            'last_seen_at' => $start,
        ]);
        $device->state()->create([
            'water_temp' => 75,
            'target_temp' => 82,
            'system_on' => false,
            'pump_on' => false,
            'heater_on' => false,
            'pump_status' => 'OFF',
            'heater_status' => 'OFF',
            'cooldown_remaining' => '--:--',
            'reported_at' => $start,
        ]);

        foreach (['set_target' => '90', 'system_on' => null, 'pump_on' => null] as $action => $value) {
            Command::query()->create([
                'device_id' => $device->id,
                'action' => $action,
                'value' => $value,
                'status' => Command::PENDING,
            ]);
        }

        $sim = app(DemoSimulator::class);
        $sim->tick($device, $start->copy()->addSecond());

        $state = $device->state()->first();
        $this->assertSame(90, $state->target_temp);
        $this->assertTrue($state->system_on);
        $this->assertTrue($state->pump_on);
        $this->assertTrue($state->heater_on);
        $this->assertSame(76.0, $state->water_temp);
        $this->assertSame('ON', $state->heater_status);
        $this->assertSame(0, Command::query()->where('status', Command::PENDING)->count());

        $device->refresh();
        $off = $start->copy()->addSeconds(30);
        $device->sim_state = array_merge($device->sim_state ?? [], [
            'heater_last_off' => $off->copy()->subSeconds(10)->toIso8601String(),
        ]);
        $device->save();
        $state->heater_on = false;
        $state->save();

        $sim->tick($device, $off);
        $state = $device->state()->first();
        $this->assertFalse($state->heater_on);
        $this->assertSame('Temp Lockout 02:20', $state->heater_status);

        Command::query()->create([
            'device_id' => $device->id,
            'action' => 'pump_off',
            'status' => Command::PENDING,
        ]);
        $state->heater_on = true;
        $state->system_on = true;
        $state->pump_on = true;
        $state->water_temp = 80;
        $state->save();
        $device->sim_state = ['heater_last_off' => null, 'parked' => false];
        $device->save();

        $coolAt = $off->copy()->addMinute();
        $sim->tick($device, $coolAt);
        $state = $device->state()->first();
        $this->assertSame('Cooling Down', $state->pump_status);
        $this->assertTrue($state->pump_on);
        $this->assertFalse($state->heater_on);
        $this->assertSame('05:00', $state->cooldown_remaining);
    }

    public function test_idle_gap_does_not_fast_forward_temperature(): void
    {
        $start = Carbon::parse('2026-06-01 15:00:00', 'UTC');
        $device = Device::query()->create([
            'uid' => 'demo-pool',
            'name' => 'Demo Pool',
            'is_demo' => true,
            'token_hash' => DeviceTokens::hash('x'),
            'channel' => Device::STABLE,
        ]);
        $device->state()->create([
            'water_temp' => 75,
            'target_temp' => 90,
            'system_on' => true,
            'pump_on' => true,
            'heater_on' => true,
            'reported_at' => $start,
        ]);
        $device->sim_state = [
            'last_temp_step' => $start->toIso8601String(),
            'parked' => false,
        ];
        $device->save();

        app(DemoSimulator::class)->tick($device, $start->copy()->addHours(3));
        $this->assertSame(75.0, $device->state()->first()->water_temp);
    }
}
