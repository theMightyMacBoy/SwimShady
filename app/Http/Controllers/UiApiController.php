<?php

namespace App\Http\Controllers;

use App\Models\Command;
use App\Models\Device;
use App\Models\HistoryEntry;
use App\Models\User;
use App\Services\Commands;
use App\Services\DemoSimulator;
use App\Support\Display;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Session-authenticated JSON the dashboard and history chart poll. */
class UiApiController extends Controller
{
    public function __construct(private readonly DemoSimulator $demo) {}

    public function state(Request $request): JsonResponse
    {
        $device = $this->device($request);
        if ($device instanceof JsonResponse) {
            return $device;
        }
        if ($device->is_demo) {
            $this->demo->tick($device);
            $device->refresh();
        }
        $device->load('state');
        $state = $device->state;
        $pending = $device->commands()->where('status', Command::PENDING)->count();

        return response()->json([
            'online' => $device->isOnline(),
            'deviceId' => $device->id,
            'deviceName' => $device->name,
            'waterTemp' => $state?->water_temp,
            'waterTempDisplay' => Display::temp($state?->water_temp),
            'targetTemp' => $state?->target_temp,
            'systemOn' => $state?->system_on,
            'pumpOn' => $state?->pump_on,
            'pumpStatus' => $state?->pump_status,
            'cooldownRemaining' => $state?->cooldown_remaining,
            'heaterOn' => $state?->heater_on,
            'heaterStatus' => $state?->heater_status,
            'reportedAt' => Display::iso($state?->reported_at),
            'pending' => $pending,
            'serverTime' => now()->utc()->toISOString(),
        ]);
    }

    public function command(Request $request): JsonResponse
    {
        $user = $request->user();
        $device = $this->device($request);
        if ($device instanceof JsonResponse) {
            return $device;
        }
        if (! $user->canControl()) {
            return response()->json(['error' => 'your account is read-only'], 403);
        }

        try {
            [$action, $value] = Commands::normalize(
                (string) $request->input('action', ''),
                $request->input('value') !== null ? (string) $request->input('value') : null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        $command = Command::query()->create([
            'device_id' => $device->id,
            'action' => $action,
            'value' => $value,
            'status' => Command::PENDING,
            'created_by' => $user->id,
        ]);

        if ($device->is_demo) {
            $this->demo->tick($device);
        }

        return response()->json(['ok' => true, 'commandId' => $command->id]);
    }

    public function history(Request $request): JsonResponse
    {
        $device = $this->device($request);
        if ($device instanceof JsonResponse) {
            return $device;
        }
        $hours = (int) $request->query('hours', 24);
        $hours = max(1, min($hours, 24 * 30));
        $cutoff = now()->subHours($hours);
        $points = HistoryEntry::query()
            ->where('device_id', $device->id)
            ->where('recorded_at', '>=', $cutoff)
            ->orderBy('recorded_at')
            ->get(['recorded_at', 'water_temp', 'target_temp', 'heater_on']);

        return response()->json([
            'hours' => $hours,
            'deviceId' => $device->id,
            'points' => $points->map(fn (HistoryEntry $point) => [
                'recordedAt' => Display::iso($point->recorded_at),
                'waterTemp' => $point->water_temp,
                'targetTemp' => $point->target_temp,
                'heaterOn' => $point->heater_on,
            ])->values(),
        ]);
    }

    private function device(Request $request): Device|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $id = (int) $request->input('device', $request->query('device', 0));
        if ($id <= 0) {
            return response()->json(['error' => 'device required'], 400);
        }
        $device = Device::query()->find($id);
        if ($device === null) {
            return response()->json(['error' => 'not found'], 404);
        }
        if (! $user->canAccess($device)) {
            return response()->json(['error' => 'no access to this device'], 403);
        }

        return $device;
    }
}
