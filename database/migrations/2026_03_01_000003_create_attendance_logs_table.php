<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->unsignedInteger('device_uid');
            $table->foreignId('device_id')->constrained('device_configs')->cascadeOnDelete();
            $table->dateTime('timestamp');
            $table->tinyInteger('punch_type')->default(0);
            $table->boolean('cloud_synced')->default(false);
            $table->timestamp('cloud_synced_at')->nullable();
            $table->unsignedInteger('cloud_sync_attempts')->default(0);
            $table->text('last_sync_error')->nullable();
            $table->timestamps();

            $table->unique(['device_uid', 'device_id', 'timestamp'], 'attendance_dedup_unique');
            $table->index(['cloud_synced', 'created_at'], 'attendance_sync_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
