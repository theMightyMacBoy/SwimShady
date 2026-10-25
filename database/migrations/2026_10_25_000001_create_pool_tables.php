<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 64)->unique();
            $table->string('name', 96);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('firmware_version', 64)->nullable();
            $table->string('channel', 16)->default('stable');
            $table->boolean('is_demo')->default(false);
            $table->json('sim_state')->nullable();
            $table->timestamps();
        });

        Schema::create('device_states', function (Blueprint $table) {
            $table->foreignId('device_id')->primary()->constrained()->cascadeOnDelete();
            $table->double('water_temp')->nullable();
            $table->unsignedSmallInteger('target_temp')->nullable();
            $table->boolean('pump_on')->nullable();
            $table->boolean('system_on')->nullable();
            $table->boolean('heater_on')->nullable();
            $table->string('heater_status', 32)->nullable();
            $table->string('pump_status', 32)->nullable();
            $table->string('cooldown_remaining', 8)->nullable();
            $table->timestamp('reported_at')->nullable();
        });

        Schema::create('user_devices', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'device_id']);
        });

        Schema::create('history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->double('water_temp')->nullable();
            $table->unsignedSmallInteger('target_temp')->nullable();
            $table->boolean('pump_on')->nullable();
            $table->boolean('system_on')->nullable();
            $table->boolean('heater_on')->nullable();
            $table->string('pump_status', 32)->nullable();
            $table->index(['device_id', 'recorded_at']);
        });

        Schema::create('commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('action', 32);
            $table->string('value', 32)->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['device_id', 'status']);
        });

        Schema::create('firmwares', function (Blueprint $table) {
            $table->id();
            $table->string('version', 64);
            $table->string('channel', 16)->default('stable')->index();
            $table->string('md5', 32);
            $table->unsignedInteger('size');
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45);
            $table->string('username', 64)->nullable();
            $table->boolean('success');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ip', 'created_at']);
        });

        Schema::create('api_requests', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45);
            $table->string('method', 8);
            $table->string('path', 256);
            $table->unsignedSmallInteger('status_code');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ip', 'created_at']);
        });

        Schema::create('ip_rules', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->index();
            $table->string('kind', 8);
            $table->string('source', 8);
            $table->string('reason', 255)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_rules');
        Schema::dropIfExists('api_requests');
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('firmwares');
        Schema::dropIfExists('commands');
        Schema::dropIfExists('history');
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('device_states');
        Schema::dropIfExists('devices');
    }
};
