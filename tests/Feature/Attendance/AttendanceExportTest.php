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

test('guests cannot export attendance', function () {
    $this->get(route('attendance.export'))->assertRedirect(route('login'));
});

test('export returns csv download', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create();
    $employee = Employee::factory()->create(['name' => 'John Doe', 'employee_code' => 'EMP-001']);

    AttendanceLog::factory()->create([
        'device_id' => $device->id,
        'employee_id' => $employee->id,
        'punch_type' => PunchType::CheckIn,
    ]);

    $response = $this->actingAs($user)
        ->get(route('attendance.export'));

    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    $response->assertHeader('content-disposition');

    $content = $response->streamedContent();
    expect($content)->toContain('Employee Name');
    expect($content)->toContain('John Doe');
    expect($content)->toContain('EMP-001');
});

test('export respects search filter', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create();
    $alice = Employee::factory()->create(['name' => 'Alice Smith']);
    $bob = Employee::factory()->create(['name' => 'Bob Jones']);

    AttendanceLog::factory()->create(['employee_id' => $alice->id, 'device_id' => $device->id]);
    AttendanceLog::factory()->create(['employee_id' => $bob->id, 'device_id' => $device->id]);

    $response = $this->actingAs($user)
        ->get(route('attendance.export', ['search' => 'Alice']));

    $content = $response->streamedContent();
    expect($content)->toContain('Alice Smith');
    expect($content)->not->toContain('Bob Jones');
});

test('export respects date filter', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create();
    $employee = Employee::factory()->create(['name' => 'Test Employee']);

    AttendanceLog::factory()->create([
        'employee_id' => $employee->id,
        'device_id' => $device->id,
        'timestamp' => now()->subDays(10),
    ]);

    AttendanceLog::factory()->create([
        'employee_id' => $employee->id,
        'device_id' => $device->id,
        'timestamp' => now(),
    ]);

    $response = $this->actingAs($user)
        ->get(route('attendance.export', ['date_from' => now()->toDateString()]));

    $content = $response->streamedContent();
    $lines = explode("\n", trim($content));
    // Header + 1 data row (only today's record)
    expect(count($lines))->toBe(2);
});

test('export csv has correct headers', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('attendance.export'));

    $content = $response->streamedContent();
    $firstLine = explode("\n", $content)[0];
    expect($firstLine)->toContain('Employee Name');
    expect($firstLine)->toContain('Employee Code');
    expect($firstLine)->toContain('Device');
    expect($firstLine)->toContain('Punch Type');
    expect($firstLine)->toContain('Timestamp');
    expect($firstLine)->toContain('Cloud Synced');
});
