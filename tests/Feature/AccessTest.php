<?php

namespace Tests\Feature;

use App\Models\Command;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_scope_devices_and_commands(): void
    {
        [$device, $token] = $this->makeDevice();
        [$other] = $this->makeDevice();
        $admin = $this->makeUser(User::ADMIN);
        $standard = $this->makeUser(User::STANDARD);
        $readonly = $this->makeUser(User::READONLY);
        $standard->devices()->attach($device->id);
        $readonly->devices()->attach($device->id);

        $this->actingAs($standard)->get('/api/ui/state?device='.$other->id)->assertForbidden();
        $this->actingAs($readonly)->post('/api/ui/command', [
            'device' => $device->id,
            'action' => 'pump_on',
        ])->assertForbidden()->assertJsonPath('error', 'your account is read-only');

        $this->actingAs($standard)->post('/api/ui/command', [
            'device' => $device->id,
            'action' => 'set_target',
            'value' => '69',
        ])->assertStatus(400);

        $ok = $this->actingAs($standard)->post('/api/ui/command', [
            'device' => $device->id,
            'action' => 'set_target',
            'value' => '86',
        ]);
        $ok->assertOk();
        $this->assertDatabaseHas('commands', [
            'device_id' => $device->id,
            'action' => 'set_target',
            'value' => '86',
            'status' => Command::PENDING,
        ]);

        $this->actingAs($admin)->post('/devices/'.$device->id.'/channel', ['channel' => 'nope'])->assertSessionHas('error');
        $this->actingAs($admin)->post('/devices/'.$device->id.'/channel', ['channel' => 'dev'])->assertRedirect();
        $this->assertSame('dev', $device->fresh()->channel);

        $this->actingAs($standard)->get('/devices')->assertForbidden();
        $this->actingAs($admin)->post('/devices', ['name' => 'Spa', 'uid' => 'spa-1'])
            ->assertRedirect()
            ->assertSessionHas('new_token');
    }

    public function test_account_password_rules_and_last_admin(): void
    {
        $admin = $this->makeUser(User::ADMIN);
        $this->actingAs($admin)->post('/account/password', [
            'current' => 'password',
            'new' => 'short',
            'confirm' => 'short',
        ])->assertSessionHas('error');

        $this->actingAs($admin)->post('/account/password', [
            'current' => 'password',
            'new' => 'long-enough',
            'confirm' => 'long-enough',
        ])->assertSessionHas('status');

        $this->seed();
        $demo = User::query()->where('username', 'demo')->first();
        $this->actingAs($demo)->post('/account/password', [
            'current' => 'demo',
            'new' => 'long-enough',
            'confirm' => 'long-enough',
        ])->assertSessionHas('error');

        $only = User::query()->where('role', User::ADMIN)->first();
        $this->actingAs($only)->post('/users/'.$only->id.'/delete')->assertSessionHas('error');
        $this->actingAs($only)->post('/users/'.$only->id, [
            'role' => User::STANDARD,
        ])->assertSessionHas('error');
    }
}
