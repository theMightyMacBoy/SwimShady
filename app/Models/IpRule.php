<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IpRule extends Model
{
    public const UPDATED_AT = null;

    public const ALLOW = 'allow';

    public const DENY = 'deny';

    public const MANUAL = 'manual';

    public const AUTO = 'auto';

    protected $fillable = [
        'ip',
        'kind',
        'source',
        'reason',
        'expires_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
