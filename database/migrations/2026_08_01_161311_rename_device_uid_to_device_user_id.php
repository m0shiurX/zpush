<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ZKTeco device carries two identifiers per enrolled user, and this schema
 * conflated them under one column name.
 *
 *  - device_user_id: the ID an operator sees on the terminal and gives to staff.
 *    It is a string on the wire, and it is what attendance records carry.
 *  - device_slot_uid: an internal slot number the device assigns. Only commands
 *    that address a slot (set user, delete user, read fingerprint) need it.
 *
 * They are not interchangeable: a user enrolled at the keypad can hold slot 1
 * and ID 101. Bulk polling used to key on the slot while the real-time listener
 * keyed on the ID, so the same punch could be stored twice under two keys.
 * See docs/adr/0001-php-buffered-read.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->renameColumn('device_uid', 'device_user_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('device_user_id')->nullable()->change();
            $table->unsignedInteger('device_slot_uid')->nullable()->after('device_user_id');
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropUnique('attendance_dedup_unique');
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->renameColumn('device_uid', 'device_user_id');
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->string('device_user_id')->change();
            $table->unique(['device_user_id', 'device_id', 'timestamp'], 'attendance_dedup_unique');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropUnique('attendance_dedup_unique');
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->renameColumn('device_user_id', 'device_uid');
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->unsignedInteger('device_uid')->change();
            $table->unique(['device_uid', 'device_id', 'timestamp'], 'attendance_dedup_unique');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('device_slot_uid');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->renameColumn('device_user_id', 'device_uid');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedInteger('device_uid')->nullable()->change();
        });
    }
};
