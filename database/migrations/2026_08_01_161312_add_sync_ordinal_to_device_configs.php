<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks how far through a device's attendance log we have already synced.
 *
 * Keyed on position rather than time: the log is append-only and comes back in
 * insertion order, but its timestamps are not monotonic — a power loss resets
 * the device clock, so year-2000 stamps can appear after 2026 ones. A timestamp
 * watermark would silently skip every punch made after such a reset.
 *
 * last_record_count exists to notice a wipe: if the device reports fewer
 * records than last time, its log was cleared and the ordinal must restart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_configs', function (Blueprint $table) {
            $table->unsignedInteger('last_synced_ordinal')->default(0)->after('last_poll_at');
            $table->unsignedInteger('last_record_count')->default(0)->after('last_synced_ordinal');
        });
    }

    public function down(): void
    {
        Schema::table('device_configs', function (Blueprint $table) {
            $table->dropColumn(['last_synced_ordinal', 'last_record_count']);
        });
    }
};
