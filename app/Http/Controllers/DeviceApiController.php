<?php

namespace App\Http\Controllers;

use App\Models\Command;
use App\Models\Device;
use App\Models\Firmware;
use App\Services\DeviceTokens;
use App\Services\HistoryRecorder;
use App\Support\ExactResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Device-facing routes. The ESP32 polls these from behind NAT.
 * Auth is the X-Device-Token header (or ?token= on the OTA download, which
 * cannot send headers). Responses always carry Content-Length.
 */
class DeviceApiController extends Controller
{
    public function __construct(private readonly HistoryRecorder $history) {}

    public function heartbeat(Request $request): Response
    {
        $token = (string) $request->header('X-Device-Token', '');
        $device = $this->deviceForToken($token);
        if ($device === null) {
            return ExactResponse::json(['error' => 'unauthorized'], 401);
        }

        $body = $this->jsonBody($request);
        $now = now();

        $device->last_seen_at = $now;
        $device->firmware_version = $this->trunc($this->stringOrNull($body['firmware_version'] ?? null), 64);
        $device->save();

        $state = $device->state ?? $device->state()->make(['device_id' => $device->id]);
        $state->fill([
            'water_temp' => $this->floatOrNull($body['water_temp'] ?? null),
            'target_temp' => $this->intOrNull($body['target_temp'] ?? null),
            'pump_on' => $this->boolOrNull($body['pump_on'] ?? null),
            'system_on' => $this->boolOrNull($body['system_on'] ?? null),
            'heater_on' => $this->boolOrNull($body['heater_on'] ?? null),
            'heater_status' => $this->trunc($this->stringOrNull($body['heater_status'] ?? null), 32),
            'pump_status' => $this->trunc($this->stringOrNull($body['pump_status'] ?? null), 32),
            'cooldown_remaining' => $this->trunc($this->stringOrNull($body['cooldown_remaining'] ?? null), 8),
            'reported_at' => $now,
        ])->save();

        $this->history->recordFromState($device, $state, $now);

        $pending = $device->commands()
            ->where('status', Command::PENDING)
            ->orderBy('id')
            ->get();
        foreach ($pending as $command) {
            $command->status = Command::DONE;
            $command->delivered_at = $now;
            $command->save();
        }

        $update = null;
        $reportedVersion = $this->stringOrNull($body['firmware_version'] ?? null);
        if ($reportedVersion !== null && $reportedVersion !== '') {
            $latest = Firmware::query()
                ->where('channel', $device->channel)
                ->orderByDesc('id')
                ->first();
            if ($latest !== null && $latest->version !== $reportedVersion) {
                $escaped = rawurlencode($token);
                $base = $request->getSchemeAndHttpHost().'/api/device/firmware';
                $update = [
                    'version' => $latest->version,
                    'url' => $base.'/bin?token='.$escaped,
                    'md5Url' => $base.'/md5?token='.$escaped,
                ];
            }
        }

        return ExactResponse::json([
            'ok' => true,
            'commands' => $pending->map(fn ($command) => [
                'id' => $command->id,
                'action' => $command->action,
                'value' => $command->value,
            ])->values(),
            'update' => $update,
            'serverTime' => $now->utc()->toISOString(),
        ]);
    }

    public function state(Request $request): Response
    {
        $token = (string) $request->header('X-Device-Token', '');
        $device = $this->deviceForToken($token);
        if ($device === null) {
            return ExactResponse::json(['error' => 'unauthorized'], 401);
        }

        $device->load('state');
        $state = $device->state;

        return ExactResponse::json([
            'hasState' => $state !== null,
            'targetTemp' => $state?->target_temp,
            'systemOn' => $state?->system_on,
            'pumpOn' => $state?->pump_on,
        ]);
    }

    public function firmwareBin(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $device = $this->deviceForToken((string) $request->query('token', ''));
        if ($device === null) {
            return ExactResponse::json(['error' => 'unauthorized'], 401);
        }
        $firmware = $this->latestFirmware($device);
        if ($firmware === null || ! is_file($firmware->absolutePath())) {
            return ExactResponse::json(['error' => 'not found'], 404);
        }

        return response()->file($firmware->absolutePath(), [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store, no-transform',
        ]);
    }

    public function firmwareMd5(Request $request): Response
    {
        $device = $this->deviceForToken((string) $request->query('token', ''));
        if ($device === null) {
            return ExactResponse::json(['error' => 'unauthorized'], 401);
        }
        $firmware = $this->latestFirmware($device);
        if ($firmware === null) {
            return ExactResponse::json(['error' => 'not found'], 404);
        }

        return ExactResponse::text($firmware->md5);
    }

    private function latestFirmware(Device $device): ?Firmware
    {
        return Firmware::query()
            ->where('channel', $device->channel)
            ->orderByDesc('id')
            ->first();
    }

    private function deviceForToken(string $token): ?Device
    {
        if ($token === '') {
            return null;
        }

        return Device::query()->where('token_hash', DeviceTokens::hash($token))->first();
    }

    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) || is_numeric($value)) {
            return (string) $value;
        }

        return null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }
        $float = (float) $value;

        return is_finite($float) ? $float : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    private function boolOrNull(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }

        return null;
    }

    private function trunc(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) <= $max ? $value : mb_substr($value, 0, $max);
    }
}
