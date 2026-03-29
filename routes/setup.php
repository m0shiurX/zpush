<?php

use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Setup Wizard Routes (No Auth Required)
|--------------------------------------------------------------------------
|
| These routes power the first-run setup wizard. They are accessible
| without authentication and are gated so that once setup is complete,
| users are redirected to the dashboard.
|
*/

Route::get('setup', [SetupController::class, 'wizard'])->name('setup.wizard');
Route::get('setup/device', [SetupController::class, 'device'])->name('setup.device');
Route::post('setup/device/test', [SetupController::class, 'testDevice'])->name('setup.device.test');
Route::post('setup/device', [SetupController::class, 'storeDevice'])->name('setup.device.store');
Route::get('setup/cloud', [SetupController::class, 'cloud'])->name('setup.cloud');
Route::post('setup/cloud', [SetupController::class, 'storeCloud'])->name('setup.cloud.store');
Route::post('setup/cloud/test', [SetupController::class, 'testCloud'])->name('setup.cloud.test');
Route::post('setup/cloud/branches', [SetupController::class, 'fetchBranches'])->name('setup.cloud.branches');
Route::post('setup/cloud/skip', [SetupController::class, 'skipCloud'])->name('setup.cloud.skip');
Route::get('setup/complete', [SetupController::class, 'complete'])->name('setup.complete');
Route::post('setup/finalize', [SetupController::class, 'finalize'])->name('setup.finalize');
