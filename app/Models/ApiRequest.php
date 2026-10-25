<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiRequest extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'ip',
        'method',
        'path',
        'status_code',
        'user_id',
    ];
}
