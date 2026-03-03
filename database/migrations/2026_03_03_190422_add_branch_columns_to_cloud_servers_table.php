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
        Schema::table('cloud_servers', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('api_key');
            $table->string('branch_name')->nullable()->after('branch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cloud_servers', function (Blueprint $table) {
            $table->dropColumn(['branch_id', 'branch_name']);
        });
    }
};
