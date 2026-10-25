<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MariaDB gives the first TIMESTAMP column DEFAULT CURRENT_TIMESTAMP unless
        // DEFAULT NULL is spelled out. That stamp made a new device look online.
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE devices MODIFY last_seen_at TIMESTAMP NULL DEFAULT NULL');
        }

        DB::table('devices')
            ->where('is_demo', false)
            ->whereNotIn('id', function ($query) {
                $query->select('device_id')->from('device_states');
            })
            ->update(['last_seen_at' => null]);
    }

    public function down(): void
    {
        // Keep last_seen_at nullable. Restoring the implicit current-time default
        // is what made unused devices look online.
    }
};
