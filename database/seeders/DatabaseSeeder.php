<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\User;
use App\Services\DeviceTokens;
use App\Services\IpSecurity;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! User::query()->exists()) {
            User::query()->create([
                'username' => 'admin',
                'password' => 'admin',
                'role' => User::ADMIN,
            ]);
        }

        $demoDevice = Device::query()->where('is_demo', true)->first();
        if ($demoDevice === null) {
            $demoDevice = Device::query()->create([
                'uid' => 'demo-pool',
                'name' => 'Demo Pool',
                'is_demo' => true,
                'token_hash' => DeviceTokens::hash(DeviceTokens::new()),
                'last_seen_at' => now(),
                'firmware_version' => config('swimshady.demo_firmware_version'),
                'channel' => Device::STABLE,
            ]);
            $demoDevice->state()->create([
                'water_temp' => 75,
                'target_temp' => 82,
                'system_on' => false,
                'pump_on' => false,
                'heater_on' => false,
                'heater_status' => 'OFF',
                'pump_status' => 'OFF',
                'cooldown_remaining' => '--:--',
                'reported_at' => now(),
            ]);
        } elseif (
            $demoDevice->firmware_version !== config('swimshady.demo_firmware_version')
            || $demoDevice->channel !== Device::STABLE
        ) {
            $demoDevice->forceFill([
                'firmware_version' => config('swimshady.demo_firmware_version'),
                'channel' => Device::STABLE,
            ])->save();
        }

        $demo = User::query()->where('username', 'demo')->first();
        if ($demo === null) {
            $demo = User::query()->create([
                'username' => 'demo',
                'password' => 'demo',
                'role' => User::STANDARD,
            ]);
        }
        $demo->devices()->syncWithoutDetaching([$demoDevice->id]);

        app(IpSecurity::class)->syncWhitelist();
    }
}
