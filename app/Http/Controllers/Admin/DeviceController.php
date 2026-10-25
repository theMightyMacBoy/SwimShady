<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\DemoSimulator;
use App\Services\DeviceTokens;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function __construct(private readonly DemoSimulator $demo) {}

    public function index()
    {
        $devices = Device::query()->orderBy('name')->get();
        foreach ($devices as $device) {
            if ($device->is_demo) {
                $this->demo->tick($device);
            }
        }

        return view('devices.index', [
            'devices' => Device::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $uid = strtolower(trim((string) $request->input('uid', '')));
        $name = trim((string) $request->input('name', ''));
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/', $uid)) {
            return back()->with('error', 'UID must be lowercase letters, numbers, and hyphens (2–64 characters).')->withInput();
        }
        if ($name === '') {
            return back()->with('error', 'Name is required.')->withInput();
        }
        if (Device::query()->where('uid', $uid)->exists()) {
            return back()->with('error', 'A device with that UID already exists.')->withInput();
        }

        $token = DeviceTokens::new();
        Device::query()->create([
            'uid' => $uid,
            'name' => $name,
            'token_hash' => DeviceTokens::hash($token),
            'channel' => Device::STABLE,
        ]);

        return redirect()->route('devices.index')->with([
            'new_token' => $token,
            'new_token_uid' => $uid,
        ]);
    }

    public function regenerate(Device $device)
    {
        $token = DeviceTokens::new();
        $device->token_hash = DeviceTokens::hash($token);
        $device->save();

        return redirect()->route('devices.index')->with([
            'new_token' => $token,
            'new_token_uid' => $device->uid,
        ]);
    }

    public function channel(Request $request, Device $device)
    {
        $channel = (string) $request->input('channel', '');
        if (! in_array($channel, Device::CHANNELS, true)) {
            return back()->with('error', 'Invalid channel.');
        }
        $device->channel = $channel;
        $device->save();

        return redirect()->route('devices.index');
    }

    public function destroy(Device $device)
    {
        $device->delete();

        return redirect()->route('devices.index');
    }
}
