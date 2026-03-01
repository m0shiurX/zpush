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
        Schema::create('cloud_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('api_base_url');
            $table->text('api_key');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_connected')->default(false);
            $table->timestamp('last_successful_sync')->nullable();
            $table->timestamp('last_failed_sync')->nullable();
            $table->unsignedInteger('sync_failure_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cloud_servers');
    }
};
