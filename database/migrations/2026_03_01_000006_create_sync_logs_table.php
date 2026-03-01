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
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cloud_server_id')->nullable()->constrained('cloud_servers')->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('device_configs')->nullOnDelete();
            $table->string('direction');
            $table->string('entity_type');
            $table->unsignedInteger('records_affected')->default(0);
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
