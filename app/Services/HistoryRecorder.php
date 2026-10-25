<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceState;
use App\Models\HistoryEntry;
use Illuminate\Support\Carbon;

class HistoryRecorder
{
    public function recordFromState(Device $device, DeviceState $state, Carbon $now): void
    {
        $this->record(
            $device->id,
            $now,
            $state->water_temp,
            $state->target_temp,
            $state->pump_on,
            $state->system_on,
            $state->heater_on,
            $state->pump_status,
        );
    }

    public function record(
        int $deviceId,
        Carbon $now,
        ?float $water,
        ?int $target,
        ?bool $pump,
        ?bool $system,
        ?bool $heater,
        ?string $pumpStatus,
    ): void {
        $interval = (int) config('swimshady.history_interval_seconds');
        $cutoff = $now->copy()->subSeconds(max(0, $interval));
        $recent = HistoryEntry::query()
            ->where('device_id', $deviceId)
            ->where('recorded_at', '>=', $cutoff)
            ->exists();
        if ($recent) {
            return;
        }

        HistoryEntry::query()->create([
            'device_id' => $deviceId,
            'recorded_at' => $now,
            'water_temp' => $water,
            'target_temp' => $target,
            'pump_on' => $pump,
            'system_on' => $system,
            'heater_on' => $heater,
            'pump_status' => $pumpStatus,
        ]);
    }
}
