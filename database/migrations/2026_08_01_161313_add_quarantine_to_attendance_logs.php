<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holds records whose device timestamp cannot be trusted.
 *
 * When a device loses power its clock restarts at 2000-01-01, and any punch
 * made before an operator notices carries a meaningless timestamp. Those
 * records are still real events, so they are stored rather than dropped — but
 * they are withheld from cloud sync until a human decides what they mean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->boolean('is_quarantined')->default(false)->after('punch_type');
            $table->index(['is_quarantined', 'cloud_synced'], 'attendance_quarantine_index');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex('attendance_quarantine_index');
            $table->dropColumn('is_quarantined');
        });
    }
};
