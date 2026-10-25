<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoryEntry extends Model
{
    public $timestamps = false;

    protected $table = 'history';

    protected $fillable = [
        'device_id',
        'recorded_at',
        'water_temp',
        'target_temp',
        'pump_on',
        'system_on',
        'heater_on',
        'pump_status',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'water_temp' => 'float',
            'target_temp' => 'integer',
            'pump_on' => 'boolean',
            'system_on' => 'boolean',
            'heater_on' => 'boolean',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
