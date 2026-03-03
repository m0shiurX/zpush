<?php

use App\Models\AppSetting;
use App\Models\Employee;
use App\Models\User;

beforeEach(function () {
    AppSetting::set('setup_completed', true);
});

// ==========================================
// Index
// ==========================================

test('guests cannot access employee index', function () {
    $this->get(route('employees.index'))->assertRedirect(route('login'));
});

test('employee index renders with employees', function () {
    $user = User::factory()->create();
    Employee::factory()->count(3)->create();

    $this->actingAs($user)
        ->get(route('employees.index'))
        ->assertOk()
        ->assertInertia(
            fn($page) => $page
                ->component('employees/Index')
                ->has('employees.data', 3)
        );
});

test('employee index paginates results', function () {
    $user = User::factory()->create();
    Employee::factory()->count(30)->create();

    $this->actingAs($user)
        ->get(route('employees.index'))
        ->assertOk()
        ->assertInertia(
            fn($page) => $page
                ->has('employees.data', 25)
                ->where('employees.total', 30)
        );
});

// ==========================================
// Filters
// ==========================================

test('employee index filters by name search', function () {
    $user = User::factory()->create();
    Employee::factory()->create(['name' => 'Alice Smith']);
    Employee::factory()->create(['name' => 'Bob Jones']);

    $this->actingAs($user)
        ->get(route('employees.index', ['search' => 'Alice']))
        ->assertOk()
        ->assertInertia(
            fn($page) => $page
                ->has('employees.data', 1)
                ->where('employees.data.0.name', 'Alice Smith')
        );
});

test('employee index filters by employee code', function () {
    $user = User::factory()->create();
    Employee::factory()->create(['employee_code' => 'EMP-001']);
    Employee::factory()->create(['employee_code' => 'EMP-999']);

    $this->actingAs($user)
        ->get(route('employees.index', ['search' => 'EMP-001']))
        ->assertOk()
        ->assertInertia(
            fn($page) => $page
                ->has('employees.data', 1)
        );
});

test('employee index filters by active status', function () {
    $user = User::factory()->create();
    Employee::factory()->count(3)->create(['is_active' => true]);
    Employee::factory()->count(2)->inactive()->create();

    $this->actingAs($user)
        ->get(route('employees.index', ['status' => 'active']))
        ->assertOk()
        ->assertInertia(
            fn($page) => $page
                ->has('employees.data', 3)
        );
});

test('employee index filters by inactive status', function () {
    $user = User::factory()->create();
    Employee::factory()->count(3)->create(['is_active' => true]);
    Employee::factory()->count(2)->inactive()->create();

    $this->actingAs($user)
        ->get(route('employees.index', ['status' => 'inactive']))
        ->assertOk()
        ->assertInertia(
            fn($page) => $page
                ->has('employees.data', 2)
        );
});

test('employee index includes attendance log count', function () {
    $user = User::factory()->create();
    $employee = Employee::factory()->create();
    $device = \App\Models\DeviceConfig::factory()->create();

    \App\Models\AttendanceLog::factory()->count(5)->create([
        'employee_id' => $employee->id,
        'device_id' => $device->id,
    ]);

    $this->actingAs($user)
        ->get(route('employees.index'))
        ->assertOk()
        ->assertInertia(
            fn($page) => $page
                ->where('employees.data.0.attendance_logs_count', 5)
        );
});

test('employee index returns filter values', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('employees.index', ['search' => 'test', 'status' => 'active']))
        ->assertOk()
        ->assertInertia(
            fn($page) => $page
                ->where('filters.search', 'test')
                ->where('filters.status', 'active')
        );
});
