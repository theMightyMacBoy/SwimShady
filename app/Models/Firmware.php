<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Firmware extends Model
{
    protected $table = 'firmwares';

    protected $fillable = [
        'version',
        'channel',
        'md5',
        'size',
        'notes',
    ];

    protected static function booted(): void
    {
        static::deleted(function (Firmware $firmware) {
            Storage::disk('local')->delete($firmware->storagePath());
        });
    }

    public function storagePath(): string
    {
        return 'firmware/'.$this->id.'.bin';
    }

    public function absolutePath(): string
    {
        return Storage::disk('local')->path($this->storagePath());
    }
}
