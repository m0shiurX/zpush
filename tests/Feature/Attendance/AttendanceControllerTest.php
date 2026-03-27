<?php

use App\Enums\PunchType;
use App\Models\AppSetting;
use App\Models\AttendanceLog;
use App\Models\DeviceConfig;
use App\Models\Employee;
use App\Models\User;

beforeEach(function () {
    AppSetting::set('setup_completed', true);
});

// ==========================================
// Index
// ==========================================

test('guests cannot access attendance index', function () {
    $this->get(route('attendance.index'))->assertRedirect(route('login'));
});

test('attendance index renders with logs', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create();
    $employee = Employee::factory()->create();

    AttendanceLog::factory()->count(3)->create([
        'device_id' => $device->id,
        'employee_id' => $employee->id,
    ]);

    $this->actingAs($user)
        ->get(route('attendance.index'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('attendance/Index')
                ->has('logs.data', 3)
        );
});

test('attendance index paginates results', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create();

    AttendanceLog::factory()->count(30)->create([
        'device_id' => $device->id,
    ]);

    $this->actingAs($user)
        ->get(route('attendance.index'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->has('logs.data', 25)
                ->where('logs.total', 30)
        );
});

// ==========================================
// Filters
// ==========================================

test('attendance index filters by employee search', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create();
    $alice = Employee::factory()->create(['name' => 'Alice Smith']);
    $bob = Employee::factory()->create(['name' => 'Bob Jones']);

    AttendanceLog::factory()->create(['employee_id' => $alice->id, 'device_id' => $device->id]);
    AttendanceLog::factory()->create(['employee_id' => $bob->id, 'device_id' => $device->id]);

    $this->actingAs($user)
        ->get(route('attendance.index', ['search' => 'Alice']))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.employee_name', 'Alice Smith')
        );
});

test('attendance index filters by date range', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create();
    $employee = Employee::factory()->create();

    AttendanceLog::factory()->create([
        'employee_id' => $employee->id,
        'device_id' => $device->id,
        'timestamp' => now()->subDays(5),
    ]);
    AttendanceLog::factory()->create([
        'employee_id' => $employee->id,
        'device_id' => $device->id,
        'timestamp' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('attendance.index', ['date_from' => now()->toDateString()]))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->has('logs.data', 1)
        );
});

test('attendance index filters by punch type', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create();
    $employee = Employee::factory()->create();

    AttendanceLog::factory()->checkIn()->create(['employee_id' => $employee->id, 'device_id' => $device->id]);
    AttendanceLog::factory()->checkOut()->create(['employee_id' => $employee->id, 'device_id' => $device->id]);

    $this->actingAs($user)
        ->get(route('attendance.index', ['punch_type' => PunchType::CheckIn->value]))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.punch_label', 'Check In')
        );
});

test('attendance index returns filter values', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('attendance.index', ['search' => 'test', 'date_from' => '2025-01-01']))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('filters.search', 'test')
                ->where('filters.date_from', '2025-01-01')
        );
});
