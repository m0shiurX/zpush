# ZPush — Laravel ZKTeco Attendance Middleware

## Project Plan & Architecture (v3 — Web-First, Senior Review)

> **Last updated:** 2026-03-01
>
> **Strategy:** Build as a standard Laravel 12 web app first. Get core features working and tested against a real ZKTeco K40 device. Wrap in NativePHP for desktop distribution as a **separate final phase** — all NativePHP-specific code is isolated and deferred.

---

## 1. Project Overview

A **web application** (later wrappable in NativePHP) that acts as a **middleware bridge** between a **ZKTeco K40** biometric attendance device (LAN) and a **cloud application** (remote API). It runs on the same network as the device, polls for attendance data, manages employee records, and performs bi-directional synchronization with the cloud.

The application must work **fully offline** — collecting attendance and managing device users even without internet. Cloud sync is an enhancement, not a requirement.

### Core Objectives

1. Connect to ZKTeco K40 device via `mehedijaman/laravel-zkteco` package
2. Store data locally in SQLite for offline resilience and NativePHP compatibility
3. Sync attendance logs to the cloud when internet is available
4. Bi-directional employee data sync (cloud ↔ local ↔ device)
5. First-run setup wizard (no auth required) so any user can configure the app
6. Fully functional without internet

### What Already Exists (Do NOT Rebuild)

The project is scaffolded from the Laravel 12 Vue starter kit. These are already working:

- **Auth system** — Fortify login, registration, password reset, 2FA, email verification
- **User model** — with roles (Spatie Permission), status enum, factory
- **Settings pages** — profile, password, appearance, account management
- **Layouts** — `AppLayout.vue` (sidebar + header), `AuthLayout.vue`
- **UI component library** — shadcn-vue (reka-ui based): button, card, dialog, table, tabs, sidebar, badge, alert, input, select, skeleton, spinner, tooltip, dropdown, sheet, etc.
- **Wayfinder** — TypeScript route generation from Laravel routes
- **Tailwind CSS v4** via Vite plugin
- **ESLint + Prettier** configured
- **Database queue** — jobs table migration already exists
- **`composer run dev`** — concurrent server, queue, logs, vite

---

## 2. Technology Stack

| Layer           | Technology                        | Rationale                                                     |
| --------------- | --------------------------------- | ------------------------------------------------------------- |
| Backend         | **Laravel 12** (PHP 8.4)          | Already installed. Streamlined structure, no Kernel files.    |
| Database        | **SQLite** (WAL mode)             | Already default in `.env`. NativePHP-ready. Single-user.      |
| Frontend        | **Vue 3 + Inertia.js v2**         | Already installed. Deferred props, prefetch, useForm.         |
| Styling         | **Tailwind CSS v4**               | Already installed via Vite plugin.                            |
| UI Components   | **shadcn-vue (reka-ui)**          | Already installed. 24+ components available.                  |
| Route Gen       | **Wayfinder**                     | Already installed. TypeScript route functions.                |
| Device SDK      | `mehedijaman/laravel-zkteco`      | To install. PHP wrapper for ZKTeco TCP protocol.              |
| HTTP Client     | Laravel HTTP (Guzzle)             | Built-in. Cloud API communication.                            |
| Queue           | SQLite-backed (`database` driver) | Already configured. Jobs persist across restarts.             |
| Auth            | **Fortify + Spatie Permission**   | Already installed. NOT used for setup wizard.                 |
| Testing         | **Pest 4**                        | Already installed. Feature + unit tests.                      |
| Scheduler       | Laravel Task Scheduling           | Standard cron for web. Swap to NativePHP Scheduler API later. |
| Desktop (later) | NativePHP (Electron)              | **DEFERRED.** Added as final phase.                           |

### What to Install

```bash
composer require mehedijaman/laravel-zkteco
```

That's it. Everything else is already in `composer.json` and `package.json`.

---

## 3. Development Strategy — Web-First, Speed-First

### Why Web-First?

1. **Faster iteration.** `composer run dev` gives instant hot-reload. No Electron rebuild cycle.
2. **Real device testing immediately.** The web server runs on the same LAN as the K40 — TCP connection works identically.
3. **Standard Laravel patterns only.** No NativePHP-specific code leaks into business logic.
4. **NativePHP wrapping is cosmetic.** All it does is serve the same Laravel app in an Electron shell, manage SQLite location, and replace cron with its Scheduler API. This is a 1-2 day task at the end.

### NativePHP Isolation Rule

All NativePHP-specific code will live in:
- `app/Providers/NativeAppServiceProvider.php` — window, tray, scheduler
- `.env` overrides for SQLite path

**Zero NativePHP imports in controllers, services, models, or jobs.** The app must work identically via `php artisan serve` and inside NativePHP.

---

## 4. Database Design (SQLite + WAL)

### SQLite Configuration

```php
// config/database.php — already configured as default
'sqlite' => [
    'driver' => 'sqlite',
    'database' => env('DB_DATABASE', database_path('database.sqlite')),
    'foreign_key_constraints' => true,
    'journal_mode' => 'WAL',           // Enable WAL for concurrent reads
    'synchronous' => 'NORMAL',         // Good balance of safety + speed
    'busy_timeout' => 5000,            // 5s wait on locks
],
```

### New Tables (Migrations to Create)

> Existing tables (users, cache, jobs, permissions, etc.) remain untouched.

```
device_configs
├── id (PK)
├── name (string — "Main Entrance")
├── ip_address (string)
├── port (integer, default 4370)
├── is_active (boolean, default true)
├── last_connected_at (timestamp, nullable)
├── last_poll_at (timestamp, nullable)
├── connection_failures (integer, default 0)
├── created_at
└── updated_at

employees
├── id (PK)
├── cloud_id (nullable, unique — ID from cloud system)
├── device_uid (nullable — UID on ZKTeco device)
├── name (string)
├── employee_code (string, unique — primary identifier across systems)
├── card_number (nullable)
├── department (nullable)
├── is_active (boolean, default true)
├── cloud_synced_at (timestamp, nullable)
├── device_synced_at (timestamp, nullable)
├── sync_hash (string, nullable — md5 of syncable fields)
├── created_at
└── updated_at

attendance_logs
├── id (PK)
├── employee_id (FK → employees, nullable)
├── device_uid (integer — user ID on device)
├── device_id (FK → device_configs)
├── timestamp (datetime — punch time from device)
├── punch_type (tinyint: 0=check-in, 1=check-out, 2=break-out, 3=break-in, 4=OT-in, 5=OT-out)
├── cloud_synced (boolean, default false)
├── cloud_synced_at (timestamp, nullable)
├── cloud_sync_attempts (integer, default 0)
├── last_sync_error (text, nullable)
├── created_at
└── updated_at
├── UNIQUE INDEX on (device_uid, device_id, timestamp)

cloud_servers
├── id (PK)
├── name (string, nullable — fetched from cloud)
├── api_base_url (string)
├── api_key (string — `encrypted` cast)
├── is_active (boolean, default true)
├── is_connected (boolean, default false)
├── last_successful_sync (timestamp, nullable)
├── last_failed_sync (timestamp, nullable)
├── sync_failure_count (integer, default 0)
├── created_at
└── updated_at

sync_queue
├── id (PK)
├── direction (string — cloud_up, cloud_down, device_up, device_down)
├── entity_type (string — employee, attendance)
├── entity_id (integer, nullable)
├── payload (json)
├── priority (integer, default 0 — higher = processed first)
├── status (string — pending, processing, completed, failed)
├── attempts (integer, default 0)
├── max_attempts (integer, default 5)
├── last_error (text, nullable)
├── scheduled_at (timestamp)
├── completed_at (timestamp, nullable)
├── created_at
└── updated_at

sync_logs
├── id (PK)
├── cloud_server_id (FK, nullable)
├── device_id (FK, nullable)
├── direction (string)
├── entity_type (string)
├── records_affected (integer)
├── status (string — success, failed, partial)
├── error_message (text, nullable)
├── duration_ms (integer)
├── started_at (timestamp)
└── completed_at (timestamp)

app_settings
├── id (PK)
├── key (string, unique)
├── value (text — JSON-encoded)
├── created_at
└── updated_at
```

### Key `app_settings` Rows

| Key                         | Default        | Purpose                            |
| --------------------------- | -------------- | ---------------------------------- |
| `setup_completed`           | `false`        | Controls setup wizard redirect     |
| `sync_interval_seconds`     | `30`           | How often to poll device           |
| `timezone`                  | `"Asia/Dhaka"` | Display timezone                   |
| `offline_mode`              | `false`        | User can force offline             |
| `auto_clear_device_logs`    | `false`        | Auto-clear synced logs from device |
| `device_log_retention_days` | `90`           | Days before auto-clear             |

---

## 5. Application Architecture

### High-Level Flow

```
┌─────────────────┐     ┌──────────────────────────────────────┐     ┌─────────────────┐
│   ZKTeco K40     │     │     Laravel Web App                  │     │  Cloud Server    │
│   (LAN Device)   │     │     (Laravel 12 + SQLite + Vue 3)    │     │  (Remote API)    │
│                  │     │                                      │     │                  │
│  ◄── TCP/IP ───► │◄───►│  DeviceService ──► SQLite (SoT)     │     │                  │
│                  │     │                      │               │     │                  │
│  • Fingerprints  │     │  Vue UI (Inertia) ◄──┤               │     │                  │
│  • Punch logs    │     │                      │               │     │                  │
│  • User records  │     │  Sync Engine ────────┤──────────────►│  REST API        │
│                  │     │  (Queue + Schedule)   │◄──────────────│  /api/v1/...     │
└─────────────────┘     └──────────────────────────────────────┘     └─────────────────┘

Data Flow Priority:
  1. Device → Local SQLite   (always runs, even offline)
  2. Local → Cloud            (runs when internet available)
  3. Cloud → Local → Device   (runs when internet available)
```

### Module Breakdown

```
app/
├── Http/Controllers/
│   ├── SetupController.php           — First-run wizard (NO auth)
│   ├── DashboardController.php       — Main dashboard
│   ├── DeviceController.php          — Device CRUD & connection testing
│   ├── EmployeeController.php        — Employee list & detail
│   ├── AttendanceController.php      — Attendance log & export
│   ├── CloudServerController.php     — Cloud server CRUD & testing
│   ├── SyncController.php            — Manual sync triggers & monitor
│   └── Settings/ (EXISTING)          — Already has user/role/permission settings
├── Http/Middleware/
│   ├── EnsureSetupComplete.php       — Redirects to wizard if not set up
│   └── HandleInertiaRequests.php     — EXISTING, extend with appStatus
├── Http/Requests/
│   ├── StoreDeviceRequest.php
│   ├── UpdateDeviceRequest.php
│   ├── StoreCloudServerRequest.php
│   └── TestDeviceConnectionRequest.php
├── Services/
│   ├── DeviceService.php             — ZKTeco device communication
│   ├── CloudApiService.php           — Cloud API HTTP client
│   ├── ConnectivityService.php       — Internet & cloud reachability checks
│   ├── EmployeeSyncService.php       — Bi-directional employee sync logic
│   ├── AttendanceSyncService.php     — Attendance collection & upload
│   └── SyncOrchestrator.php          — Master sync logic
├── Jobs/
│   ├── PollDeviceAttendance.php      — Fetch new punches from device
│   ├── SyncAttendanceToCloud.php     — Push unsynced logs to cloud
│   ├── SyncEmployeesFromCloud.php    — Pull employee changes from cloud
│   ├── SyncEmployeesToDevice.php     — Push names/codes to device
│   ├── CheckCloudConnectivity.php    — Periodic connectivity ping
│   └── ProcessSyncQueue.php          — Drain the sync_queue table
├── Models/
│   ├── User.php (EXISTING)
│   ├── Employee.php
│   ├── AttendanceLog.php
│   ├── DeviceConfig.php
│   ├── CloudServer.php
│   ├── SyncQueue.php
│   ├── SyncLog.php
│   └── AppSetting.php
├── Enums/
│   ├── UserStatus.php (EXISTING)
│   ├── PunchType.php
│   ├── SyncDirection.php
│   └── SyncStatus.php
├── Events/
│   ├── DeviceConnected.php
│   ├── DeviceDisconnected.php
│   ├── SyncCompleted.php
│   └── SyncFailed.php
└── Console/Commands/
    ├── PollDevices.php               — `php artisan devices:poll` (manual trigger)
    ├── SyncToCloud.php               — `php artisan sync:cloud` (manual trigger)
    └── FlushSyncedLogs.php           — Housekeeping
```

### Route Structure

```php
// routes/web.php — Setup wizard (NO auth)
Route::middleware('guest.setup')->group(function () {
    Route::get('setup', [SetupController::class, 'welcome'])->name('setup.welcome');
    Route::get('setup/device', [SetupController::class, 'device'])->name('setup.device');
    Route::post('setup/device/test', [SetupController::class, 'testDevice'])->name('setup.device.test');
    Route::post('setup/device', [SetupController::class, 'storeDevice'])->name('setup.device.store');
    Route::get('setup/cloud', [SetupController::class, 'cloud'])->name('setup.cloud');
    Route::post('setup/cloud/test', [SetupController::class, 'testCloud'])->name('setup.cloud.test');
    Route::post('setup/cloud', [SetupController::class, 'storeCloud'])->name('setup.cloud.store');
    Route::post('setup/complete', [SetupController::class, 'complete'])->name('setup.complete');
});

// routes/devices.php — Device & attendance management (NO auth for now)
Route::middleware('setup.complete')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('devices', DeviceController::class);
    Route::post('devices/{device}/test', [DeviceController::class, 'test'])->name('devices.test');
    Route::post('devices/{device}/poll', [DeviceController::class, 'poll'])->name('devices.poll');

    Route::resource('employees', EmployeeController::class)->only(['index', 'show']);

    Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('attendance/export', [AttendanceController::class, 'export'])->name('attendance.export');

    Route::get('sync', [SyncController::class, 'index'])->name('sync.index');
    Route::post('sync/now', [SyncController::class, 'syncNow'])->name('sync.now');

    Route::resource('cloud-servers', CloudServerController::class);
    Route::post('cloud-servers/{cloudServer}/test', [CloudServerController::class, 'test'])->name('cloud-servers.test');
});
```

---

## 6. First-Run Setup Wizard

No auth required. The wizard gates the entire app — if `app_settings.setup_completed` is false, every route redirects to `/setup`.

### Wizard Steps

| Step | Page                      | Purpose                             | Required? |
| ---- | ------------------------- | ----------------------------------- | --------- |
| 1    | `Setup/Welcome.vue`       | App intro, what it does             | Yes       |
| 2    | `Setup/DeviceConnect.vue` | Device IP, port, name + test button | Yes       |
| 3    | `Setup/CloudConfig.vue`   | Cloud URL + API key, or "skip"      | No        |
| 4    | `Setup/Complete.vue`      | Summary + "Open Dashboard"          | Yes       |

### EnsureSetupComplete Middleware

```php
class EnsureSetupComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!AppSetting::get('setup_completed', false)) {
            return redirect()->route('setup.welcome');
        }

        return $next($request);
    }
}
```

---

## 7. DeviceService — ZKTeco Communication

This is the core service. Wraps `mehedijaman/laravel-zkteco` with error handling and retry logic.

```php
class DeviceService
{
    /**
     * Test connection to a device. Returns device info on success.
     *
     * @return array{serial_number: string, device_name: string, user_count: int, log_count: int}
     *
     * @throws DeviceConnectionException
     */
    public function testConnection(string $ip, int $port = 4370): array;

    /**
     * Fetch all attendance logs from device since last poll.
     * Returns raw log data as array of arrays.
     *
     * @return array<int, array{uid: int, id: string, state: int, timestamp: string}>
     */
    public function getAttendanceLogs(DeviceConfig $device): array;

    /**
     * Fetch all users registered on the device.
     *
     * @return array<int, array{uid: int, id: string, name: string, role: int, cardno: string}>
     */
    public function getUsers(DeviceConfig $device): array;

    /**
     * Add a user to the device.
     */
    public function addUser(DeviceConfig $device, int $uid, string $userId, string $name): bool;

    /**
     * Remove a user from the device.
     */
    public function removeUser(DeviceConfig $device, int $uid): bool;

    /**
     * Clear all attendance logs from device (after confirmed sync).
     */
    public function clearAttendanceLogs(DeviceConfig $device): bool;
}
```

---

## 8. Offline-First Architecture

### Connectivity States

| Device       | Internet | Behavior                      |
| ------------ | -------- | ----------------------------- |
| Connected    | Online   | Full operation — poll + sync  |
| Connected    | Offline  | Poll device, queue cloud sync |
| Disconnected | Online   | Drain sync queue to cloud     |
| Disconnected | Offline  | Show cached data, wait        |

### Sync Queue Pattern

All cloud-bound operations write to `sync_queue` first. `ProcessSyncQueue` drains it when cloud is reachable.

```php
// When attendance is polled from device:
$log = AttendanceLog::create([...]);    // Always saved locally first

SyncQueue::create([                      // Queued for cloud upload
    'direction' => SyncDirection::CloudUp,
    'entity_type' => 'attendance',
    'entity_id' => $log->id,
    'payload' => $log->toSyncPayload(),
    'status' => SyncStatus::Pending,
    'scheduled_at' => now(),
]);
```

### Backoff Strategy

```
Attempt 1 → retry after 30s
Attempt 2 → retry after 60s
Attempt 3 → retry after 2 min
Attempt 4 → retry after 5 min
Attempt 5 → marked as failed
```

---

## 9. Queue & Scheduling (Web-First)

### Queue Configuration

Already configured — `database` driver with SQLite. The jobs table migration exists.

```bash
# Already runs via `composer run dev`:
php artisan queue:listen --tries=1 --timeout=0
```

### Task Scheduling (for web, replace with NativePHP Scheduler later)

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::job(new PollDeviceAttendance)->everyThirtySeconds();
Schedule::job(new ProcessSyncQueue)->everyThirtySeconds();
Schedule::job(new CheckCloudConnectivity)->everyMinute();
Schedule::job(new SyncEmployeesFromCloud)->everyFiveMinutes();
Schedule::command('sync:flush-old')->daily();
```

Run scheduler in dev:

```bash
php artisan schedule:work
```

### Queue Priority

| Queue     | Jobs                                | Purpose              |
| --------- | ----------------------------------- | -------------------- |
| `high`    | `PollDeviceAttendance`              | Must always run fast |
| `default` | `ProcessSyncQueue`, cloud sync jobs | Normal priority      |
| `low`     | `FlushSyncedLogs`, housekeeping     | Can wait             |

---

## 10. HandleInertiaRequests — Global Shared Data

Extend the existing middleware to share app-wide status:

```php
// Add to existing share() method in HandleInertiaRequests.php

'appStatus' => fn () => [
    'setup_completed' => AppSetting::get('setup_completed', false),
    'devices' => DeviceConfig::where('is_active', true)
        ->get(['id', 'name', 'last_connected_at', 'connection_failures'])
        ->map(fn ($d) => [
            'id' => $d->id,
            'name' => $d->name,
            'connected' => $d->connection_failures === 0
                           && $d->last_connected_at?->gt(now()->subMinutes(2)),
        ]),
    'cloud' => [
        'configured' => CloudServer::where('is_active', true)->exists(),
        'connected' => CloudServer::where('is_connected', true)->exists(),
        'last_sync' => CloudServer::max('last_successful_sync'),
    ],
    'pending_sync' => [
        'attendance' => AttendanceLog::where('cloud_synced', false)->count(),
        'employees' => SyncQueue::where('entity_type', 'employee')
                          ->where('status', SyncStatus::Pending)->count(),
    ],
],
```

---

## 11. Cloud API Contract

### Endpoints the Cloud Must Expose

```
POST   /api/v1/ping                  — Health check + server name
POST   /api/v1/auth/validate         — Validate API key

GET    /api/v1/employees             — List all (?updated_since=ISO8601)
POST   /api/v1/employees             — Create (from device enrollment)
PUT    /api/v1/employees/{code}      — Update by employee_code

POST   /api/v1/attendance/bulk       — Upload batch (max 200 per request)
       Body: { records: [{ employee_code, timestamp, punch_type, device_name }] }
       Response: { accepted: 198, rejected: 2, rejected_ids: [...] }

POST   /api/v1/sync/heartbeat        — Client alive + pending counts
```

### Authentication Header

```
Authorization: Bearer {api_key}
X-Client-Version: 1.0.0
X-Client-ID: {unique install id from app_settings}
```

---

## 12. Employee Bi-Directional Sync

### Conflict Resolution

| Source | Is Master For                                            |
| ------ | -------------------------------------------------------- |
| Cloud  | Employee name, department, active status, employee code  |
| Device | New enrollments (fingerprint), attendance records        |
| Local  | Queue state, cloud_id ↔ device_uid mapping, offline data |

### Change Detection

```php
// Employee model — auto-compute sync_hash on save
protected static function booted(): void
{
    static::saving(function (Employee $employee): void {
        $employee->sync_hash = md5(json_encode([
            $employee->name,
            $employee->department,
            $employee->employee_code,
            $employee->is_active,
        ]));
    });
}
```

---

## 13. Error Handling & Resilience

| Failure              | Handling                                          | User Sees                                |
| -------------------- | ------------------------------------------------- | ---------------------------------------- |
| Device unreachable   | Increment `connection_failures`, retry next cycle | Red device indicator, "Last seen: X ago" |
| Cloud API down       | Queue items stay pending, backoff delays increase | "Cloud offline" banner + pending count   |
| Cloud API 401        | Mark `is_connected = false`, stop syncing         | "Auth failed — check API key"            |
| Internet restored    | Queue drains immediately                          | Banner clears, "Syncing X records..."    |
| SQLite locked        | WAL mode + `busy_timeout` prevents this           | Transparent                              |
| Duplicate attendance | UNIQUE constraint catches, skip silently          | Transparent                              |
| Device time drift    | Normalize using device-reported time + offset     | Transparent, logged for admin review     |

---

## 14. Vue Pages & Components

### New Pages to Build

> All pages use the existing `AppLayout.vue` (sidebar + header). Setup wizard uses a new `SetupLayout.vue` (clean, no sidebar).

```
resources/js/pages/
├── setup/
│   ├── Welcome.vue                    — Step 1: intro
│   ├── DeviceConnect.vue              — Step 2: device IP + test
│   ├── CloudConfig.vue                — Step 3: cloud (optional)
│   └── Complete.vue                   — Step 4: summary
├── devices/
│   ├── Index.vue                      — Device list + status
│   └── Show.vue                       — Single device detail + test + poll
├── employees/
│   └── Index.vue                      — Employee list with sync status
├── attendance/
│   └── Index.vue                      — Attendance log with date filters
├── sync/
│   └── Monitor.vue                    — Sync activity log + manual trigger
└── Dashboard.vue                      — EXISTING, extend with device/sync status
```

### New Components to Build

```
resources/js/components/
├── StatusBadge.vue                    — Green/yellow/red dot + label
├── ConnectionTester.vue               — Test button with live result feedback
├── SyncProgress.vue                   — Progress bar for bulk sync operations
└── OfflineBanner.vue                  — Top bar when cloud is unreachable
```

### Layout Note

Use existing `AppLayout.vue` for all authenticated/main pages. Create `SetupLayout.vue` only for the wizard — minimal chrome, centered content, step indicator.

---

## 15. Development Phases (Revised for Speed)

### Phase 1 — Models, Migrations, DeviceService (2-3 days)

> Get the data layer and device communication working. Prove the ZKTeco connection.

- [ ] Enable SQLite WAL mode in `config/database.php`
- [ ] Install `mehedijaman/laravel-zkteco` package
- [ ] Create migrations: `device_configs`, `employees`, `attendance_logs`, `cloud_servers`, `sync_queue`, `sync_logs`, `app_settings`
- [ ] Create models with factories: `DeviceConfig`, `Employee`, `AttendanceLog`, `CloudServer`, `SyncQueue`, `SyncLog`, `AppSetting`
- [ ] Create enums: `PunchType`, `SyncDirection`, `SyncStatus`
- [ ] Build `DeviceService` — connect, test, fetch users, fetch attendance
- [ ] Create `PollDevices` artisan command for manual testing
- [ ] Write Pest tests for `DeviceService` against real device
- [ ] Run `vendor/bin/pint --dirty --format agent`

### Phase 2 — Setup Wizard + Core UI (3-4 days)

> First user experience. Setup wizard → device config → dashboard.

- [ ] Create `SetupLayout.vue` (clean wizard layout)
- [ ] Build `SetupController` with all 4 steps
- [ ] Create `EnsureSetupComplete` middleware, register in `bootstrap/app.php`
- [ ] Build `Setup/Welcome.vue`, `Setup/DeviceConnect.vue`, `Setup/CloudConfig.vue`, `Setup/Complete.vue`
- [ ] Build `ConnectionTester.vue` component (reusable for device + cloud)
- [ ] Build `StatusBadge.vue` component
- [ ] Create setup routes (no auth)
- [ ] Write Pest tests for setup flow
- [ ] Run `vendor/bin/pint --dirty --format agent`

### Phase 3 — Device Polling + Attendance UI (3-4 days)

> Core value: see attendance data from the device in the browser.

- [ ] Build `PollDeviceAttendance` job with `ShouldBeUnique`
- [ ] Build `AttendanceSyncService` — dedup logic, employee matching
- [ ] Extend `Dashboard.vue` with device status cards + today's punch count
- [ ] Build `Devices/Index.vue` + `Devices/Show.vue` with test/poll buttons
- [ ] Build `Attendance/Index.vue` with date filters, search, pagination
- [ ] Build `Employees/Index.vue` from device user data
- [ ] Add `OfflineBanner.vue` component
- [ ] Configure task scheduling in `routes/console.php`
- [ ] Extend `HandleInertiaRequests` with `appStatus` shared data
- [ ] Add device/sync sidebar items to existing `AppSidebar`
- [ ] Write Pest tests for polling, dedup, attendance controller
- [ ] Run `vendor/bin/pint --dirty --format agent`

### Phase 4 — Cloud Sync Engine (3-4 days)

> Cloud integration. Offline-first queue. Bi-directional employee sync.

- [ ] Build `CloudApiService` — HTTP client with auth headers
- [ ] Build `ConnectivityService` — reachability checks
- [ ] Build `ProcessSyncQueue` job — drain queue with backoff
- [ ] Build `SyncAttendanceToCloud` — batch upload (200/request)
- [ ] Build `SyncEmployeesFromCloud` — pull + hash comparison
- [ ] Build `SyncEmployeesToDevice` — push new employees to K40
- [ ] Build `CloudServerController` — CRUD + test connection
- [ ] Build `Sync/Monitor.vue` — live sync log + manual trigger
- [ ] Build `SyncProgress.vue` component
- [ ] Write Pest tests for sync queue, cloud API service, employee sync
- [ ] Run `vendor/bin/pint --dirty --format agent`

### Phase 5 — Polish & Export (2-3 days)

> Settings, export, resilience improvements.

- [ ] Build app settings page (sync interval, timezone, log retention)
- [ ] Export attendance to CSV
- [ ] Manual "Sync Now" button on dashboard
- [ ] Sync history with filtering on `Sync/Monitor.vue`
- [ ] Improve error messages and validation feedback across all pages
- [ ] Run full Pest test suite, fix coverage gaps
- [ ] Run `vendor/bin/pint --dirty --format agent`

### Phase 6 — NativePHP Desktop Wrapper (DEFERRED)

> Wrap the working web app in NativePHP. This is a separate sprint.

- [ ] Install NativePHP (`php artisan native:install`)
- [ ] Create `NativeAppServiceProvider` — window config, tray icon
- [ ] Replace `routes/console.php` schedule with NativePHP Scheduler API
- [ ] Configure SQLite path for OS app data directory
- [ ] System tray icon with status indicator
- [ ] App keeps running in tray when window closed
- [ ] Build for Windows (`php artisan native:build`)
- [ ] Test fresh install experience
- [ ] Auto-update mechanism

---

## 16. Key Considerations

**SQLite Performance:** WAL mode + `busy_timeout` + proper indexes. Index: `attendance_logs(cloud_synced, created_at)`, `attendance_logs(device_uid, device_id, timestamp)`, `sync_queue(status, scheduled_at, priority)`, `employees(employee_code)`.

**Device Limitations:** ZKTeco K40 supports ~1,000 users. `device_uid` is integer 1–65535. `DeviceService` must manage UID allocation when pushing new employees.

**Timezone:** Store all timestamps in UTC. Device reports local time — normalize during polling. Display in user's configured timezone. Share timezone to Vue via Inertia for client-side formatting.

**Security:** API keys encrypted at rest via Laravel `encrypted` cast. Cloud communication must be HTTPS.

**Testing:** Every phase includes Pest tests. Use `php artisan test --compact --filter=FeatureName` for fast feedback. Factories for all models.

**No Over-Engineering:** Skip Events system for now. Use simple method calls in services. Add events/listeners when a real need arises (e.g., when we need WebSocket push for real-time UI updates).

### Existing Packages to Leverage

| Package              | Already Installed | Use For                                    |
| -------------------- | ----------------- | ------------------------------------------ |
| Spatie Permission    | Yes               | Role-based access if needed later          |
| Spatie Activity Log  | Yes               | Audit trail for sync operations            |
| Spatie Backup        | Yes               | SQLite database backup                     |
| Spatie Media Library | Yes               | Not needed for this feature set            |
| @tanstack/vue-table  | Yes               | Attendance + employee table UI             |
| @vueuse/core         | Yes               | Composables (polling, online status, etc.) |
| lucide-vue-next      | Yes               | Icons for status indicators                |

---

## 17. Files That Will NOT Be Modified

These existing files should NOT be touched during implementation:

- `app/Models/User.php` and related auth controllers
- `app/Http/Controllers/Account/*`
- `app/Http/Controllers/Settings/*` (existing settings)
- `app/Providers/FortifyServiceProvider.php`
- Auth-related Vue pages (`pages/auth/*`, `pages/account/*`, `pages/settings/*`)
- Existing layouts, sidebar, header components (extend, don't rewrite)
- `database/migrations/0001_*` (existing migrations)

---

## 18. Definition of Done (Before NativePHP)

Before moving to Phase 6 (NativePHP), ALL of these must work:

1. Fresh app → setup wizard completes → dashboard shows device status
2. Device polling runs on schedule, attendance appears in UI within 30s
3. Employee list populated from device, shows sync status
4. Attendance page with working date filters, search, pagination
5. Cloud server can be added, tested, and syncs attendance in batches
6. Offline mode: cloud unreachable → data queues → auto-syncs on restore
7. All Pest tests passing
8. `vendor/bin/pint` clean