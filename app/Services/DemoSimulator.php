<?php

namespace App\Services;

use App\Models\Command;
use App\Models\Device;
use App\Models\DeviceState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Virtual ESP32 for the public Demo Pool. Advances on demand (page polls and
 * queued commands) so it pauses while nobody is watching, the same way the old
 * Azure simulator paused when the free plan went idle.
 *
 * Heater gating, the 2:30 anti-short-cycle lockout, and the 5-minute fireman's
 * cooldown follow the firmware. Water rests at 75°F, heats +1°F / 5s toward the
 * target, and cools −1°F / 10s back to baseline.
 */
class DemoSimulator
{
    public const BASELINE = 75.0;

    public const HEAT_STEP_SECONDS = 5;

    public const COOL_STEP_SECONDS = 10;

    public const LOCKOUT_SECONDS = 150;

    public const COOLDOWN_SECONDS = 300;

    public const IDLE_GAP_SECONDS = 90;

    public function __construct(private readonly HistoryRecorder $history) {}

    public function tick(Device $device, ?Carbon $now = null): void
    {
        $now = ($now ?? now())->copy();

        DB::transaction(function () use ($device, $now) {
            $locked = Device::query()->lockForUpdate()->find($device->id);
            if ($locked === null || ! $locked->is_demo) {
                return;
            }
            $state = DeviceState::query()->lockForUpdate()->find($locked->id);
            if ($state === null) {
                return;
            }
            $this->step($locked, $state, $now);
        });
    }

    private function step(Device $device, DeviceState $state, Carbon $now): void
    {
        $sim = $device->sim_state ?? [];
        $heaterLastOff = isset($sim['heater_last_off']) ? Carbon::parse($sim['heater_last_off']) : null;
        $cooldownDeadline = isset($sim['cooldown_deadline']) ? Carbon::parse($sim['cooldown_deadline']) : Carbon::createFromTimestampUTC(0);
        $parked = (bool) ($sim['parked'] ?? false);
        $lastTempStep = isset($sim['last_temp_step']) ? Carbon::parse($sim['last_temp_step']) : null;

        $temp = $state->water_temp ?? self::BASELINE;
        $target = $state->target_temp ?? 82;
        $systemOn = (bool) $state->system_on;
        $pumpOn = (bool) $state->pump_on;
        $heaterOn = (bool) $state->heater_on;

        $shutdown = function () use (&$heaterOn, &$heaterLastOff, &$cooldownDeadline, &$parked, &$pumpOn, $now): void {
            if ($heaterOn) {
                $heaterOn = false;
                $heaterLastOff = $now->copy();
            }
            $sinceOff = $heaterLastOff ? $this->elapsed($heaterLastOff, $now) : self::COOLDOWN_SECONDS;
            $needed = max(0, self::COOLDOWN_SECONDS - $sinceOff);
            $deadline = $now->copy()->addSeconds($needed);
            if ($deadline->greaterThan($cooldownDeadline)) {
                $cooldownDeadline = $deadline;
            }
            if ($now->lessThan($cooldownDeadline)) {
                $parked = true;
            } else {
                $parked = false;
                $pumpOn = false;
            }
        };

        $pending = Command::query()
            ->where('device_id', $device->id)
            ->where('status', Command::PENDING)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($pending as $command) {
            switch ($command->action) {
                case 'set_target':
                    $target = max(70, min(104, (int) $command->value));
                    break;
                case 'system_on':
                    $systemOn = true;
                    break;
                case 'system_off':
                    $systemOn = false;
                    if ($heaterOn) {
                        $heaterOn = false;
                        $heaterLastOff = $now->copy();
                    }
                    break;
                case 'pump_on':
                    $pumpOn = true;
                    $parked = false;
                    break;
                case 'pump_off':
                    $shutdown();
                    break;
                case 'cancel_cooldown':
                    $parked = false;
                    $pumpOn = true;
                    break;
            }
            $command->status = Command::DONE;
            $command->delivered_at = $now;
            $command->save();
        }

        if ($parked && $now->greaterThanOrEqualTo($cooldownDeadline)) {
            $parked = false;
            $pumpOn = false;
        }

        $lockout = $heaterLastOff !== null && $this->elapsed($heaterLastOff, $now) < self::LOCKOUT_SECONDS;
        $wantHeat = $systemOn && $pumpOn && ! $parked && $temp < $target && $temp < 104;

        if ($wantHeat && ! $lockout) {
            $heaterOn = true;
        } elseif ($heaterOn) {
            $heaterOn = false;
            $heaterLastOff = $now->copy();
        }

        $heating = $heaterOn && $temp < $target;
        $cooling = ! $heaterOn && $temp > self::BASELINE;
        $gap = $state->reported_at ? $this->elapsed($state->reported_at, $now) : 0;

        if ($gap > self::IDLE_GAP_SECONDS) {
            $lastTempStep = $now->copy();
        } elseif ($heating) {
            $since = $lastTempStep ? $this->elapsed($lastTempStep, $now) : self::HEAT_STEP_SECONDS;
            $steps = min(30, intdiv(max(0, $since), self::HEAT_STEP_SECONDS));
            for ($i = 0; $i < $steps && $temp < $target && $temp < 104; $i++) {
                $temp += 1;
            }
            if ($steps > 0) {
                $anchor = $lastTempStep ?? $now->copy()->subSeconds($since);
                $lastTempStep = $anchor->addSeconds($steps * self::HEAT_STEP_SECONDS);
            }
        } elseif ($cooling) {
            $since = $lastTempStep ? $this->elapsed($lastTempStep, $now) : self::COOL_STEP_SECONDS;
            $steps = min(30, intdiv(max(0, $since), self::COOL_STEP_SECONDS));
            for ($i = 0; $i < $steps && $temp > self::BASELINE; $i++) {
                $temp -= 1;
            }
            if ($steps > 0) {
                $anchor = $lastTempStep ?? $now->copy()->subSeconds($since);
                $lastTempStep = $anchor->addSeconds($steps * self::COOL_STEP_SECONDS);
            }
        } else {
            $lastTempStep = $now->copy();
        }

        $pumpStatus = $parked ? 'Cooling Down' : ($pumpOn ? 'ON' : 'OFF');
        if ($heaterOn) {
            $heaterStatus = 'ON';
        } elseif ($wantHeat && $lockout && $heaterLastOff !== null) {
            $remaining = self::LOCKOUT_SECONDS - $this->elapsed($heaterLastOff, $now);
            $heaterStatus = 'Temp Lockout '.$this->mmss($remaining);
        } else {
            $heaterStatus = 'OFF';
        }
        $cooldownRemaining = $now->lessThan($cooldownDeadline)
            ? $this->mmss($cooldownDeadline->getTimestamp() - $now->getTimestamp())
            : '--:--';

        $state->fill([
            'water_temp' => round($temp, 1),
            'target_temp' => $target,
            'system_on' => $systemOn,
            'pump_on' => $pumpOn,
            'heater_on' => $heaterOn,
            'heater_status' => $heaterStatus,
            'pump_status' => $pumpStatus,
            'cooldown_remaining' => $cooldownRemaining,
            'reported_at' => $now,
        ])->save();

        $device->forceFill([
            'last_seen_at' => $now,
            'sim_state' => [
                'heater_last_off' => $heaterLastOff?->utc()->toIso8601String(),
                'cooldown_deadline' => $cooldownDeadline->getTimestamp() > 0 ? $cooldownDeadline->utc()->toIso8601String() : null,
                'parked' => $parked,
                'last_temp_step' => $lastTempStep?->utc()->toIso8601String(),
            ],
        ])->save();

        $this->history->recordFromState($device, $state, $now);
    }

    private function elapsed(Carbon $from, Carbon $to): int
    {
        return $to->getTimestamp() - $from->getTimestamp();
    }

    private function mmss(int $totalSeconds): string
    {
        if ($totalSeconds < 0) {
            $totalSeconds = 0;
        }

        return sprintf('%02d:%02d', intdiv($totalSeconds, 60), $totalSeconds % 60);
    }
}
