<?php

namespace App\Services;

use App\Models\ApiRequest;
use App\Models\IpRule;
use App\Models\LoginAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class IpSecurity
{
    public function clientIp(Request $request): string
    {
        $ip = $request->ip();

        return ($ip !== null && $ip !== '') ? $ip : 'unknown';
    }

    public function isBlocked(string $ip): bool
    {
        $rules = $this->activeRules($ip);

        if ($rules->contains(fn (IpRule $rule) => $rule->kind === IpRule::ALLOW)) {
            return false;
        }

        return $rules->contains(fn (IpRule $rule) => $rule->kind === IpRule::DENY);
    }

    public function recordLogin(string $ip, ?string $username, bool $success): void
    {
        LoginAttempt::query()->create([
            'ip' => $ip,
            'username' => $this->trunc($username, 64),
            'success' => $success,
        ]);

        if ($success) {
            return;
        }

        $window = now()->subSeconds((int) config('swimshady.ban_window_seconds'));
        $fails = LoginAttempt::query()
            ->where('ip', $ip)
            ->where('success', false)
            ->where('created_at', '>=', $window)
            ->count();

        if ($fails >= (int) config('swimshady.ban_threshold')) {
            $this->autoBan($ip, $fails.' failed logins in '.(int) config('swimshady.ban_window_seconds').'s');
        }
    }

    public function recordApi(string $ip, string $method, string $path, int $status, ?int $userId, bool $countFailure): void
    {
        ApiRequest::query()->create([
            'ip' => $ip,
            'method' => $this->trunc($method, 8) ?? '',
            'path' => $this->trunc($path, 256) ?? '',
            'status_code' => $status,
            'user_id' => $userId,
        ]);

        if (! $countFailure || ($status !== 401 && $status !== 403)) {
            return;
        }

        $window = now()->subSeconds((int) config('swimshady.ban_window_seconds'));
        $fails = ApiRequest::query()
            ->where('ip', $ip)
            ->whereIn('status_code', [401, 403])
            ->where('created_at', '>=', $window)
            ->count();

        if ($fails >= (int) config('swimshady.ban_threshold')) {
            $this->autoBan($ip, $fails.' auth failures in '.(int) config('swimshady.ban_window_seconds').'s');
        }
    }

    public function syncWhitelist(): void
    {
        $raw = (string) config('swimshady.whitelist_ips');
        foreach (preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ip) {
            $exists = IpRule::query()
                ->where('ip', $ip)
                ->where('kind', IpRule::ALLOW)
                ->exists();
            if ($exists) {
                continue;
            }
            IpRule::query()->create([
                'ip' => $ip,
                'kind' => IpRule::ALLOW,
                'source' => IpRule::MANUAL,
                'reason' => 'configured whitelist',
            ]);
        }
    }

    private function autoBan(string $ip, string $reason): void
    {
        $rules = IpRule::query()->where('ip', $ip)->get();
        if ($rules->contains(fn (IpRule $rule) => $rule->kind === IpRule::ALLOW && $rule->isActive())) {
            return;
        }
        $already = $rules->contains(function (IpRule $rule) {
            return $rule->kind === IpRule::DENY
                && $rule->source === IpRule::AUTO
                && $rule->isActive();
        });
        if ($already) {
            return;
        }

        IpRule::query()->create([
            'ip' => $ip,
            'kind' => IpRule::DENY,
            'source' => IpRule::AUTO,
            'reason' => $this->trunc($reason, 255),
            'expires_at' => now()->addMinutes((int) config('swimshady.ban_duration_minutes')),
        ]);
    }

    /** @return Collection<int, IpRule> */
    private function activeRules(string $ip)
    {
        return IpRule::query()
            ->where('ip', $ip)
            ->get()
            ->filter(fn (IpRule $rule) => $rule->isActive())
            ->values();
    }

    private function trunc(?string $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return mb_strlen($value) <= $max ? $value : mb_substr($value, 0, $max);
    }
}
