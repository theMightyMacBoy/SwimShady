<?php

namespace Tests;

use App\Models\Device;
use App\Models\User;
use App\Services\DeviceTokens;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function makeUser(string $role = User::STANDARD): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /** @return array{0: Device, 1: string} */
    protected function makeDevice(array $overrides = []): array
    {
        $token = DeviceTokens::new();
        $device = Device::query()->create(array_merge([
            'uid' => 'pool-'.bin2hex(random_bytes(3)),
            'name' => 'Pool',
            'token_hash' => DeviceTokens::hash($token),
            'channel' => Device::STABLE,
            'is_demo' => false,
        ], $overrides));

        return [$device, $token];
    }
}
