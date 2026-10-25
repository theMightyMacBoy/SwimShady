<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\DemoSimulator;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DemoSimulator $demo) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $devices = $this->visible($user);
        foreach ($devices as $device) {
            if ($device->is_demo) {
                $this->demo->tick($device);
            }
        }
        $devices = $this->visible($user);

        $selected = (int) $request->query('device', 0);
        $current = $devices->firstWhere('id', $selected) ?? $devices->first();

        return view('dashboard', [
            'devices' => $devices,
            'current' => $current,
            'canControl' => $user->canControl(),
        ]);
    }

    public function history(Request $request)
    {
        $user = $request->user();
        $devices = $this->visible($user);
        $selected = (int) $request->query('device', 0);
        $current = $devices->firstWhere('id', $selected) ?? $devices->first();

        return view('history', [
            'current' => $current,
        ]);
    }

    private function visible($user)
    {
        $query = Device::query()->orderBy('name');
        if (! $user->isAdmin()) {
            $query->whereHas('users', fn ($q) => $q->whereKey($user->id));
        }

        return $query->get();
    }
}
