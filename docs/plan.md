# ZPush — Laravel ZKTeco Attendance Middleware

## Project Plan & Architecture (v4 — Web-First, Cloud-Integrated)

> **Last updated:** 2026-03-04
>
> **Strategy:** Build as a standard Laravel 12 web app first. Get core features working and tested against a real ZKTeco K40 device. Wrap in NativePHP for desktop distribution as a **separate final phase** — all NativePHP-specific code is isolated and deferred.
>
> **Cloud:** The cloud application is **lavloss** — a Laravel 12 ERP with a full HRM module (employees, departments, shifts, attendance, payroll). zpush acts as a branch-level middleware bridge that syncs employees down from lavloss and pushes processed daily attendance up.

---

## 1. Project Overview

A **web application** (later wrappable in NativePHP) that acts as a **middleware bridge** between **ZKTeco K40** biometric attendance devices (LAN) and the **lavloss cloud ERP** (remote API). Each zpush instance is deployed at a physical **branch** (factory, office, branch) on the same LAN as the devices, polls for attendance data, manages employee records, and synchronizes with the cloud.

Multiple zpush instances can run at different physical locations, each mapped to a **branch** in lavloss. Each branch has its own departments and employees — no employee overlap between branches.

The application must work **fully offline** — collecting attendance and managing device users even without internet. Cloud sync is an enhancement, not a requirement.

### Core Objectives

1. Connect to ZKTeco K40 device(s) via `mehedijaman/laravel-zkteco` package
2. Store data locally in SQLite for offline resilience and NativePHP compatibility
3. **Process raw punch events into daily attendance records** (pair check-in/check-out locally)
4. Sync processed daily attendance UP to lavloss when internet is available
5. Sync employee data DOWN from lavloss (filtered by branch) to local + device
6. First-run setup wizard — configure device, connect to cloud, **select branch**
7. Fully functional without internet

### Multi-Branch Architecture

```
                    ┌──────────── LAVLOSS (Cloud ERP) ─────────────┐
                    │  Branches → Departments → Employees → Shifts  │
                    │  Attendance (daily processed records)          │
                    │  Payroll, Leaves, etc.                        │
                    │                                               │
                    │  API: /api/v1/zpush/...                       │
                    └──────────┬─────────────────┬──────────────────┘
                               │                 │
                   ┌───────────┘                 └──────────────┐
                   ▼                                            ▼
     ┌─── zpush (Branch A) ──┐              ┌─── zpush (Branch B) ──┐
     │  "Main Factory"       │              │  "Branch Office"      │
     │  K40 #1 (Gate)        │              │  K40 #3 (Entrance)    │
     │  K40 #2 (Floor)       │              │                       │
     │                       │              │                       │
     │  Employees: 120       │              │  Employees: 25        │
     │  (Prod + Packaging)   │              │  (Sales + Admin)      │
     └──────────────────────┘              └───────────────────────┘
```

**Key rule:** Each branch owns its employees. No employee punches at multiple branches. The cloud is the source of truth for employee data.

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
| Device SDK      | `mehedijaman/laravel-zkteco`      | Installed (dev-main for L12). Wrapped by `ZktecoTcp` for TCP. |
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
├── protocol (string, default 'tcp' — 'tcp' or 'udp')
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
├── branch_id (integer, nullable — selected branch ID in lavloss)
├── branch_name (string, nullable — denormalized for display)
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
| `cloud_branch_id`           | `null`         | Selected branch ID in lavloss      |
| `cloud_branch_name`         | `null`         | Selected branch name (display)     |
| `sync_interval_seconds`     | `30`           | How often to poll device           |
| `timezone`                  | `"Asia/Dhaka"` | Display timezone                   |
| `offline_mode`              | `false`        | User can force offline             |
| `auto_clear_device_logs`    | `false`        | Auto-clear synced logs from device |
| `device_log_retention_days` | `90`           | Days before auto-clear             |

---

## 5. Application Architecture

### High-Level Flow

```
┌─────────────────┐     ┌──────────────────────────────────────┐     ┌─────────────────────┐
│   ZKTeco K40     │     │     zpush (Laravel 12 + SQLite)      │     │  lavloss (Cloud ERP) │
│   (LAN Device)   │     │                                      │     │                      │
│  ◄── TCP/IP ───► │◄───►│  DeviceService                       │     │                      │
│                  │     │    ↓ raw punches                     │     │                      │
│  • Fingerprints  │     │  AttendanceProcessor                  │     │                      │
│  • Punch logs    │     │    ↓ daily check-in/out pairs        │     │                      │
│  • User records  │     │  SQLite (local source of truth)       │     │                      │
│                  │     │    ↓                                 │     │                      │
│                  │     │  CloudApiService ──POST attendance──►│  AttendanceService    │
│                  │     │  CloudApiService ◄──GET employees────│  /api/v1/zpush/...    │
└─────────────────┘     └──────────────────────────────────────┘     └─────────────────────┘

Data Flow:
  1. Device → zpush (raw punches)     — always runs, even offline
  2. zpush processes → daily records   — pairs check-in/out per employee per day
  3. zpush → lavloss (daily records)   — runs when internet available
  4. lavloss → zpush (employees)       — filtered by branch, runs when internet available
  5. zpush → Device (employee names)   — push names/codes to device after cloud sync
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
│   ├── DeviceService.php             — ZKTeco device communication (TCP/UDP factory)
│   ├── ZktecoTcp.php                 — TCP socket adapter for LaravelZkteco
│   ├── AttendanceProcessorService.php — Pairs raw punches → daily check-in/check-out
│   ├── CloudApiService.php           — HTTP client for lavloss API
│   ├── ConnectivityService.php       — Internet & cloud reachability checks
│   ├── EmployeeSyncService.php       — Cloud→Local employee sync (filtered by branch)
│   ├── AttendanceSyncService.php     — Local→Cloud processed attendance upload
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

| Step | Page                      | Purpose                                           | Required? |
| ---- | ------------------------- | ------------------------------------------------- | --------- |
| 1    | `Setup/Welcome.vue`       | App intro, what it does                           | Yes       |
| 2    | `Setup/DeviceConnect.vue` | Device IP, port, name + test button               | Yes       |
| 3    | `Setup/CloudConfig.vue`   | Cloud URL + API key + **select branch** from list | No        |
| 4    | `Setup/Complete.vue`      | Summary + "Open Dashboard"                        | Yes       |

### Cloud Config Step (Step 3) Detail

1. User enters cloud URL + API key
2. zpush calls `POST /api/v1/zpush/ping` to validate
3. On success, fetches `GET /api/v1/zpush/branches` — returns available branches
4. User selects which branch this zpush instance represents
5. Stores `branch_id` and `branch_name` in `cloud_servers` table and `app_settings`
6. Can be skipped entirely for offline-only operation

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

The upstream package hardcodes UDP sockets, but the K40 is TCP-only. `ZktecoTcp` extends `LaravelZkteco` with proper TCP framing (8-byte header: `\x50\x50\x82\x7d` + LE payload length). `DeviceService` uses a factory method to instantiate the correct adapter based on `DeviceConfig.protocol`.

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

## 11. Cloud API Contract (lavloss ↔ zpush)

### Authentication

zpush authenticates to lavloss using **Sanctum API tokens**. Each zpush instance has a dedicated API token created in lavloss, scoped to a specific site. The token is entered during the setup wizard (Step 3).

```
Authorization: Bearer {sanctum_token}
Accept: application/json
X-ZPush-Version: 1.0.0
```

### Endpoints (in lavloss)

All endpoints live under `/api/v1/zpush/` prefix.

```
POST   /api/v1/zpush/ping              — Validate token, return server info
GET    /api/v1/zpush/branches          — List available branches (for setup wizard)
GET    /api/v1/zpush/employees         — Employees for the given branch
         ?branch_id=N                   — Required: branch to fetch from
         ?updated_since=ISO8601         — Incremental sync support
POST   /api/v1/zpush/attendance/bulk   — Upload processed daily attendance
```

### Endpoint Details

#### `POST /api/v1/zpush/ping`

Validates the API token and returns server time.

```json
// Response 200
{
    "success": true,
    "server_time": "2026-03-04T10:30:00Z"
}
```

#### `GET /api/v1/zpush/branches`

Returns all active branches. Used during setup wizard for branch selection.

```json
// Response 200
{
    "branches": [
        { "id": 1, "name": "Main Factory", "code": "MF", "department_count": 3, "employee_count": 120 },
        { "id": 2, "name": "Branch Office", "code": "BO", "department_count": 2, "employee_count": 25 }
    ]
}
```

#### `GET /api/v1/zpush/employees`

Returns active employees for the given branch. Supports incremental sync via `updated_since` parameter.

```json
// Response 200
{
    "employees": [
        {
            "id": 42,
            "employee_code": "EMP-042",
            "name": "Rahim Ahmed",
            "department": "Production",
            "designation": "Machine Operator",
            "shift": { "name": "Morning", "start_time": "08:00", "end_time": "17:00" },
            "is_active": true,
            "updated_at": "2026-03-01T12:00:00Z"
        }
    ],
    "total": 120,
    "branch": { "id": 1, "name": "Main Factory" }
}
```

#### `POST /api/v1/zpush/attendance/bulk`

Upload processed daily attendance records. zpush pairs raw device punches into daily check-in/check-out records before sending.

```json
// Request
{
    "records": [
        {
            "employee_code": "EMP-042",
            "date": "2026-03-04",
            "check_in": "08:03",
            "check_out": "17:32",
            "source": "zpush"
        },
        {
            "employee_code": "EMP-043",
            "date": "2026-03-04",
            "check_in": "08:15",
            "check_out": null,
            "source": "zpush"
        }
    ]
}

// Response 200
{
    "accepted": 1,
    "rejected": 1,
    "errors": [
        { "employee_code": "EMP-043", "error": "check_out is required for completed attendance" }
    ]
}
```

### Data Flow: Attendance Processing (zpush-side)

zpush does NOT send raw punches to the cloud. Instead, it **processes locally** first:

```php
// AttendanceProcessorService — runs after device polling
// For each employee on a given date:
$punches = AttendanceLog::where('employee_id', $employee->id)
    ->whereDate('timestamp', $date)
    ->orderBy('timestamp')
    ->get();

$firstCheckIn = $punches->first(fn ($p) => $p->punch_type === PunchType::CheckIn);
$lastCheckOut = $punches->last(fn ($p) => $p->punch_type === PunchType::CheckOut);

// Only sync to cloud when we have a complete pair (check-in AND check-out)
// Incomplete records (check-in only) are held locally until check-out arrives
return [
    'employee_code' => $employee->employee_code,
    'date' => $date,
    'check_in' => $firstCheckIn?->timestamp->format('H:i'),
    'check_out' => $lastCheckOut?->timestamp->format('H:i'),
    'source' => 'zpush',
];
```

**Important design decisions:**
- zpush sends only first check-in and last check-out (simple pair)
- zpush does NOT calculate overtime or attendance status — lavloss has the shift rules for that
- If an employee has a split shift in lavloss, the cloud will flag the record via `needs_review`
- Incomplete records (check-in only, no check-out yet) stay in the sync queue until complete

### Data Flow: Employee Sync (cloud → zpush)

```
lavloss                zpush                     K40 Device
  │                       │                          │
  │──GET /employees─────►│                          │
  │◄─── [EMP-042, ...]───│                          │
  │                       │── upsert local DB ─────►│
  │                       │── set-user on device ──►│
  │                       │   (name + UID)           │
```

zpush stores a simplified version of each employee:
- `cloud_id` → lavloss `employees.id`
- `employee_code` → the bridge identifier (unique across systems)
- `name` → `first_name + " " + last_name` from cloud
- `department` → denormalized string (department name from cloud)
- `device_uid` → the UID assigned on the ZKTeco device

**Key rule:** Cloud is source of truth for employees. zpush never creates employees. If a new fingerprint is enrolled directly on the device, zpush flags it as "unmatched" for the admin to map to a cloud employee.

### Employee Sync Conflict Resolution

| Source | Is Master For                                           |
| ------ | ------------------------------------------------------- |
| Cloud  | Employee name, department, active status, employee code |
| Device | New enrollments (fingerprint), raw attendance punches   |
| Local  | cloud_id ↔ device_uid mapping, sync state, offline data |

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

### Phase 1 — Models, Migrations, DeviceService (2-3 days) ✅ COMPLETE

> Get the data layer and device communication working. Prove the ZKTeco connection.

- [x] Enable SQLite WAL mode in `config/database.php`
- [x] Install `mehedijaman/laravel-zkteco` package (dev-main for Laravel 12)
- [x] Create migrations: `device_configs`, `employees`, `attendance_logs`, `cloud_servers`, `sync_queue`, `sync_logs`, `app_settings`
- [x] Create models with factories: `DeviceConfig`, `Employee`, `AttendanceLog`, `CloudServer`, `SyncQueue`, `SyncLog`, `AppSetting`
- [x] Create enums: `PunchType`, `SyncDirection`, `SyncStatus`
- [x] Build `DeviceService` — connect, test, fetch users, fetch attendance
- [x] Build `ZktecoTcp` — TCP socket adapter (K40 is TCP-only, package defaults to UDP)
- [x] Add `protocol` column to `device_configs` (default 'tcp')
- [x] Create `PollDevices` artisan command for manual testing
- [x] Create `TestDeviceConnection` artisan command with `--debug` and `--protocol` options
- [x] Write Pest tests for `DeviceService` against real device
- [x] Run `vendor/bin/pint --dirty --format agent`
- [x] Verified: 84 tests passing, real K40 connected via TCP (5 users, 48 attendance records pulled)

### Phase 2 — Setup Wizard + Core UI (3-4 days) ✅ COMPLETE

> First user experience. Setup wizard → device config → dashboard.

- [x] Create `SetupLayout.vue` (clean wizard layout)
- [x] Build `SetupController` with all 4 steps
- [x] Create `EnsureSetupComplete` middleware, register in `bootstrap/app.php`
- [x] Build `Setup/Welcome.vue`, `Setup/DeviceConnect.vue`, `Setup/CloudConfig.vue`, `Setup/Complete.vue`
- [x] Build `ConnectionTester.vue` component (reusable for device + cloud)
- [x] Build `StatusBadge.vue` component
- [x] Create setup routes (no auth)
- [x] Write Pest tests for setup flow
- [x] Run `vendor/bin/pint --dirty --format agent`

### Phase 3 — Device Polling + Attendance UI (3-4 days) ✅ COMPLETE

> Core value: see attendance data from the device in the browser.

- [x] Build `PollDeviceAttendance` job with `ShouldBeUnique`
- [x] Build `AttendanceSyncService` — dedup logic, employee matching
- [x] Extend `Dashboard.vue` with device status cards + today's punch count
- [x] Build `Devices/Index.vue` + `Devices/Show.vue` with test/poll buttons
- [x] Build `Attendance/Index.vue` with date filters, search, pagination
- [x] Build `Employees/Index.vue` from device user data
- [ ] Add `OfflineBanner.vue` component (deferred to Phase 5)
- [x] Configure task scheduling in `routes/console.php`
- [x] Extend `HandleInertiaRequests` with `appStatus` shared data
- [x] Add device/sync sidebar items to existing `AppSidebar`
- [x] Write Pest tests for polling, dedup, attendance controller
- [x] Run `vendor/bin/pint --dirty --format agent`

### Phase 4 — Cloud Sync Engine (3-4 days) ✅ COMPLETE

> Cloud integration with lavloss. Offline-first queue. Branch-scoped employee sync.

**Prerequisites:** lavloss API endpoints must be built first (see Section 11). ✅ DONE

- [x] Create `branches` table in lavloss with migration
- [x] Add `branch_id` to lavloss `departments` table
- [x] Add `HasApiTokens` to lavloss `User` model
- [x] Build lavloss `ZpushController` API (ping, branches, employees, bulk attendance)
- [x] Write Pest tests for lavloss zpush API (18 tests, 70 assertions)
- [x] Add `branch_id`, `branch_name` columns to `cloud_servers` migration
- [x] Build `CloudApiService` — HTTP client wrapping lavloss `/api/v1/zpush/*` endpoints
- [x] Build `ConnectivityService` — reachability checks
- [x] Build `AttendanceProcessorService` — pairs raw punches into daily check-in/check-out
- [x] Build `ProcessSyncQueue` job — drain queue with backoff
- [x] Build `SyncAttendanceToCloud` — process + batch upload (200/request)
- [x] Build `SyncEmployeesFromCloud` — pull branch-scoped employees, upsert local, hash comparison
- [x] Build `SyncEmployeesToDevice` — push names/codes to K40 after cloud sync
- [x] Update setup wizard Step 3 to fetch branches and let user select
- [x] Build `CloudServerController` — CRUD + test connection
- [x] Build `Sync/Monitor.vue` — live sync log + manual trigger
- [x] Build `SyncProgress.vue` component
- [x] Build `cloud-servers/Index.vue` — cloud server management page
- [x] Add cloud/sync section to sidebar navigation
- [x] Fix SyncLogFactory + SyncQueueFactory column mismatches
- [x] Write Pest tests for sync queue, cloud API service, attendance processor, employee sync
- [x] Write Pest tests for CloudServerController (10 tests) and SyncController (7 tests)
- [x] Run `vendor/bin/pint --dirty --format agent`
- [x] Verified: 212 tests passing, 883 assertions

### Phase 5 — Polish & Export (2-3 days) ✅ COMPLETE

> Settings, export, resilience improvements.

- [x] Build app settings page (sync interval, timezone, log retention, poll interval, auto-sync toggle)
- [x] Build `AppSettingsController` with `UpdateAppSettingsRequest` form validation
- [x] Build `settings/AppSettings.vue` — full settings form with card layout
- [x] Add App Settings link to settings index page (mobile + desktop views)
- [x] Export attendance to CSV with `StreamedResponse` — respects all existing filters
- [x] Add Export CSV button to `attendance/Index.vue`
- [x] Manual "Sync Now" button on dashboard (Unsynced card) with feedback alerts
- [x] Add `hasCloudServer` and `lastSyncAt` props to Dashboard
- [x] Sync history with filtering on `Sync/Monitor.vue` (direction, entity type, status, date range)
- [x] Add filter UI with Select dropdowns and date inputs
- [x] Write Pest tests: AppSettingsControllerTest (11 tests), AttendanceExportTest (5 tests)
- [x] Update DashboardTest (+2 tests for cloud server status)
- [x] Update SyncControllerTest (+4 tests for filtering)
- [x] Write comprehensive usage guide (`docs/usage-guide.md`)
- [x] Run `vendor/bin/pint --dirty --format agent`
- [x] Verified: 234 tests passing, 1053 assertions

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