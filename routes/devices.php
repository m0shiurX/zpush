<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\CloudServerController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'setup.complete'])->group(function () {
    // Devices
    Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
    Route::get('devices/{device}', [DeviceController::class, 'show'])->name('devices.show');
    Route::patch('devices/{device}', [DeviceController::class, 'update'])->name('devices.update');
    Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->name('devices.destroy');
    Route::post('devices/{device}/test', [DeviceController::class, 'test'])->name('devices.test');
    Route::post('devices/{device}/poll', [DeviceController::class, 'poll'])->name('devices.poll');
    Route::post('devices/{device}/sync-time', [DeviceController::class, 'syncTime'])->name('devices.sync-time');
    Route::delete('devices/{device}/clear-attendance', [DeviceController::class, 'clearAttendance'])->name('devices.clear-attendance');
    Route::delete('devices/{device}/clear-local-attendance', [DeviceController::class, 'clearLocalAttendance'])->name('devices.clear-local-attendance');
    Route::delete('devices/{device}/clear-device-users', [DeviceController::class, 'clearDeviceUsers'])->name('devices.clear-device-users');
    Route::post('devices/{device}/listener/start', [DeviceController::class, 'startListener'])->name('devices.listener.start');
    Route::post('devices/{device}/listener/stop', [DeviceController::class, 'stopListener'])->name('devices.listener.stop');
    Route::post('devices/{device}/listener/restart', [DeviceController::class, 'restartListener'])->name('devices.listener.restart');

    // Attendance
    Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('attendance/export', [AttendanceController::class, 'export'])->name('attendance.export');

    // Employees
    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');

    // Cloud Servers
    Route::get('cloud-servers', [CloudServerController::class, 'index'])->name('cloud-servers.index');
    Route::post('cloud-servers', [CloudServerController::class, 'store'])->name('cloud-servers.store');
    Route::post('cloud-servers/{cloudServer}/test', [CloudServerController::class, 'test'])->name('cloud-servers.test');
    Route::post('cloud-servers/{cloudServer}/branches', [CloudServerController::class, 'branches'])->name('cloud-servers.branches');
    Route::post('cloud-servers/{cloudServer}/sync-attendance', [CloudServerController::class, 'syncAttendance'])->name('cloud-servers.sync-attendance');
    Route::post('cloud-servers/{cloudServer}/sync-employees', [CloudServerController::class, 'syncEmployees'])->name('cloud-servers.sync-employees');
    Route::post('cloud-servers/{cloudServer}/sync-to-device', [CloudServerController::class, 'syncToDevice'])->name('cloud-servers.sync-to-device');
    Route::delete('cloud-servers/{cloudServer}', [CloudServerController::class, 'destroy'])->name('cloud-servers.destroy');

    // Sync Monitor
    Route::get('sync', [SyncController::class, 'index'])->name('sync.index');
    Route::post('sync/trigger', [SyncController::class, 'triggerSync'])->name('sync.trigger');
});
