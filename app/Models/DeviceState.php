<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceState extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'device_id';

    protected $fillable = [
        'device_id',
        'water_temp',
        'target_temp',
        'pump_on',
        'system_on',
        'heater_on',
        'heater_status',
        'pump_status',
        'cooldown_remaining',
        'reported_at',
    ];

    protected function casts(): array
    {
        return [
            'water_temp' => 'float',
            'target_temp' => 'integer',
            'pump_on' => 'boolean',
            'system_on' => 'boolean',
            'heater_on' => 'boolean',
            'reported_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
