<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable(['username', 'password', 'role', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    public const ADMIN = 'admin';

    public const STANDARD = 'standard';

    public const READONLY = 'readonly';

    public const ROLES = [self::ADMIN, self::STANDARD, self::READONLY];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'user_devices');
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ADMIN;
    }

    public function canControl(): bool
    {
        return $this->role === self::ADMIN || $this->role === self::STANDARD;
    }

    public function canAccess(Device $device): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->devices()->whereKey($device->id)->exists();
    }
}
