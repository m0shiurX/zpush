<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EmployeeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'setup.complete'])->group(function () {
    // Devices
    Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::get('devices/{device}', [DeviceController::class, 'show'])->name('devices.show');
    Route::patch('devices/{device}', [DeviceController::class, 'update'])->name('devices.update');
    Route::post('devices/{device}/test', [DeviceController::class, 'test'])->name('devices.test');
    Route::post('devices/{device}/poll', [DeviceController::class, 'poll'])->name('devices.poll');

    // Attendance
    Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');

    // Employees
    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
});
