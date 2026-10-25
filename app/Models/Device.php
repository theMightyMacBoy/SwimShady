<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Device extends Model
{
    public const STABLE = 'stable';

    public const DEV = 'dev';

    public const CHANNELS = [self::STABLE, self::DEV];

    protected $fillable = [
        'uid',
        'name',
        'token_hash',
        'last_seen_at',
        'firmware_version',
        'channel',
        'is_demo',
        'sim_state',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'is_demo' => 'boolean',
            'sim_state' => 'array',
        ];
    }

    public function state(): HasOne
    {
        return $this->hasOne(DeviceState::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(HistoryEntry::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(Command::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_devices');
    }

    protected static function booted(): void
    {
        // MariaDB stamps the first TIMESTAMP column with the current time when an
        // INSERT leaves it out, so a brand-new device looked online for the
        // offline window. Write NULL until a heartbeat records a real check-in.
        static::creating(function (Device $device): void {
            if (! array_key_exists('last_seen_at', $device->getAttributes())) {
                $device->last_seen_at = null;
            }
        });
    }

    public function isOnline(): bool
    {
        if ($this->last_seen_at === null) {
            return false;
        }

        $window = (int) config('swimshady.offline_after_seconds');

        return $this->last_seen_at->greaterThanOrEqualTo(now()->subSeconds($window));
    }

    /** never | online | offline */
    public function connectionStatus(): string
    {
        if ($this->last_seen_at === null) {
            return 'never';
        }

        return $this->isOnline() ? 'online' : 'offline';
    }
}
