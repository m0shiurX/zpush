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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cloud_id')->nullable()->unique();
            $table->unsignedInteger('device_uid')->nullable();
            $table->string('name');
            $table->string('employee_code')->unique();
            $table->string('card_number')->nullable();
            $table->string('department')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('cloud_synced_at')->nullable();
            $table->timestamp('device_synced_at')->nullable();
            $table->string('sync_hash')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
