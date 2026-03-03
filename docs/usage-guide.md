# ZPush Usage Guide

> **ZPush** is a middleware bridge between ZKTeco biometric attendance devices (LAN) and the **lavloss** cloud ERP. It runs at each physical branch, collects attendance data from devices, and syncs with the cloud.

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Cloud API Configuration (lavloss)](#2-cloud-api-configuration-lavloss)
3. [ZPush Setup Wizard](#3-zpush-setup-wizard)
4. [Connecting to the Cloud](#4-connecting-to-the-cloud)
5. [Managing Cloud Connections](#5-managing-cloud-connections)
6. [Syncing Data](#6-syncing-data)
7. [Monitoring Sync Activity](#7-monitoring-sync-activity)
8. [Application Settings](#8-application-settings)
9. [Exporting Attendance Data](#9-exporting-attendance-data)
10. [Disconnecting / Reconfiguring](#10-disconnecting--reconfiguring)
11. [Offline Mode](#11-offline-mode)
12. [Troubleshooting](#12-troubleshooting)

---

## 1. Architecture Overview

```
                    ┌──────────── LAVLOSS (Cloud ERP) ─────────────┐
                    │  /api/v1/zpush/ping                          │
                    │  /api/v1/zpush/branches                      │
                    │  /api/v1/zpush/employees                     │
                    │  /api/v1/zpush/attendance/bulk                │
                    └──────────┬─────────────────┬──────────────────┘
                               │                 │
                   ┌───────────┘                 └──────────────┐
                   ▼                                            ▼
     ┌─── zpush (Branch A) ──┐              ┌─── zpush (Branch B) ──┐
     │  factory.test          │              │  branch.test           │
     │  K40 devices           │              │  K40 devices           │
     └────────────────────────┘              └────────────────────────┘
```

- Each **zpush instance** runs at a physical location (factory, office, branch)
- It connects to local ZKTeco K40 devices on the LAN
- It pushes processed attendance UP to lavloss and pulls employees DOWN
- **Works fully offline** — cloud sync is an enhancement, not a requirement

---

## 2. Cloud API Configuration (lavloss)

Before zpush can connect to the cloud, you need to create an API token in lavloss.

### Step 1: Create a Sanctum API Token

In the **lavloss** application, run the following in Tinker or create a token via the admin panel:

```bash
cd /path/to/lavloss
php artisan tinker
```

```php
// Find or create the user that zpush will authenticate as
$user = \App\Models\User::where('email', 'admin@example.com')->first();

// Create a token for this zpush instance
$token = $user->createToken('zpush-main-factory');

// Copy this token — you'll need it for zpush setup
echo $token->plainTextToken;
// Output: 1|bx2GJdycmtPgc6lkCkcx3u1tqpM42ySu111qxVug49754c06
```

> **Important:** Copy the full token including the prefix (e.g., `1|abc123...`). This is the only time you'll see the plain text token.

### Step 2: Note Your lavloss URL

Your lavloss API base URL will be something like:

- **Local dev:** `http://lavloss.test` or `https://lavloss.test`
- **Production:** `https://your-erp-domain.com`

### API Endpoints Available

| Endpoint                              | Method | Purpose                           |
| ------------------------------------- | ------ | --------------------------------- |
| `/api/v1/zpush/ping`                  | POST   | Test connection & auth            |
| `/api/v1/zpush/branches`              | GET    | List branches for setup           |
| `/api/v1/zpush/employees?branch_id=X` | GET    | Pull employees for a branch       |
| `/api/v1/zpush/attendance/bulk`       | POST   | Push attendance records (max 500) |

All endpoints require a **Bearer token** in the `Authorization` header.

---

## 3. ZPush Setup Wizard

When you first open zpush, it guides you through a 4-step setup wizard.

### Step 1: Welcome

Introduction screen. Click "Get Started".

### Step 2: Device Connection

Configure your ZKTeco K40 device:

| Field       | Example         | Notes                   |
| ----------- | --------------- | ----------------------- |
| Device Name | "Main Gate K40" | Descriptive label       |
| IP Address  | 192.168.1.201   | Device's IP on your LAN |
| Port        | 4370            | Default ZKTeco port     |

Click **Test Connection** to verify the device is reachable. A green "Connected" badge means success.

### Step 3: Cloud Configuration (Optional)

Connect to lavloss cloud:

| Field            | Value                                              |
| ---------------- | -------------------------------------------------- |
| Cloud Server URL | `https://your-erp-domain.com`                      |
| API Key          | The token from Step 2 above (e.g., `1\|abc123...`) |

1. Enter the URL and API key
2. Click **Test Connection** — it calls `/api/v1/zpush/ping`
3. If successful, click **Fetch Branches** to load available branches
4. Select the branch this zpush instance represents
5. Click **Save & Continue**

> **Skip this step** if you want to run zpush offline-only. You can configure the cloud later from the Cloud Servers page.

### Step 4: Complete

Summary of your configuration. Click **Go to Dashboard** to start using zpush.

---

## 4. Connecting to the Cloud

If you skipped cloud config during setup, or want to add/change the cloud server:

1. Go to **Cloud Servers** in the sidebar (under the Cloud section)
2. Enter the **API URL** and **API Key**
3. Click **Test Connection** to verify
4. Click **Fetch Branches** and select your branch
5. Click **Save Configuration**

### What Happens After Connecting

- zpush stores the cloud server config locally
- The selected branch is saved — all employee syncs will be scoped to this branch
- Automatic sync begins on the configured schedule (default: every 30 minutes)

---

## 5. Managing Cloud Connections

### Viewing Connection Status

The **Cloud Servers** page shows:
- Server URL and connection status
- Selected branch name
- Recent sync logs with status, direction, and record counts

### Testing the Connection

Click **Test Connection** anytime to verify the cloud server is reachable and the API key is still valid. This calls the `/api/v1/zpush/ping` endpoint.

---

## 6. Syncing Data

### Automatic Sync

When configured, zpush automatically:
- **Pushes attendance** — Pairs check-in/check-out punches and sends processed daily records to lavloss every sync interval
- **Pulls employees** — Fetches employee data from lavloss (filtered by branch) using incremental sync (only fetching changes since the last successful sync)
- **Pushes to device** — Optionally writes employee names/codes to the ZKTeco device so the display shows employee names during check-in

### Manual Sync

You can trigger a sync at any time from two places:

1. **Dashboard** — Click the **Sync Now** button in the "Unsynced" stat card
2. **Sync Monitor** — Click the **Sync Now** button at the top right

Both trigger three jobs:
- `SyncAttendanceToCloud` — Sends unsynced attendance to lavloss
- `SyncEmployeesFromCloud` — Pulls employee updates from lavloss
- `ProcessSyncQueue` — Processes any pending queue items

### Sync from Cloud Servers Page

The Cloud Servers page has three specific sync buttons:
- **Sync Attendance** — Push only attendance records
- **Sync Employees** — Pull only employee data
- **Sync to Device** — Push employee names to the ZKTeco device

### Attendance Sync Details

zpush processes raw device punches into daily records before sending to lavloss:

```json
{
  "records": [
    {
      "employee_code": "EMP-042",
      "date": "2025-01-15",
      "check_in": "08:15",
      "check_out": "17:30",
      "source": "zpush"
    }
  ]
}
```

- Uses **first check-in** and **last check-out** of each day per employee
- Incomplete records (check-in only, no check-out yet) are held locally until complete
- Sends in batches of up to 200 records per request (lavloss limit: 500)

---

## 7. Monitoring Sync Activity

Navigate to **Sync Monitor** in the sidebar.

### Dashboard Cards

| Card               | Meaning                             |
| ------------------ | ----------------------------------- |
| Pending Attendance | Local records not yet sent to cloud |
| Synced Attendance  | Records successfully uploaded       |
| Queue Pending      | Sync jobs waiting to process        |
| Queue Failed       | Jobs that encountered errors        |

### Progress Bar

Shows the percentage of attendance records that have been synced to the cloud.

### Sync History Table

Displays recent sync operations with:
- **Direction** — Local → Cloud, Cloud → Local, Device → Local, etc.
- **Type** — Attendance or Employee
- **Source** — Which cloud server or device
- **Records** — How many records were affected
- **Status** — Completed, Failed, Processing, Pending
- **Duration** — How long the sync took
- **Time** — When it happened

### Filtering Sync History

Click the **Filters** button to filter by:
- Direction (Local → Cloud, Cloud → Local, etc.)
- Entity type (Attendance, Employee)
- Status (Completed, Failed, Processing, Pending)
- Date range (From / To)

Click **Clear** to reset all filters.

---

## 8. Application Settings

Go to **Settings** → **App Settings** to configure:

### Device Polling
- **Poll Interval** — How often zpush fetches new attendance from devices (default: 5 minutes)

### Cloud Sync
- **Auto Sync Enabled** — Toggle automatic cloud syncing on/off
- **Sync Interval** — How often to push attendance and pull employees (default: 30 minutes)

### Timezone
- **Timezone** — Used for displaying and processing attendance timestamps

### Data Retention
- **Log Retention** — How long to keep sync logs (default: 90 days)

---

## 9. Exporting Attendance Data

Navigate to **Attendance** in the sidebar.

1. Use filters to narrow down records (search, date range)
2. Click the **Export CSV** button in the top right
3. A CSV file downloads with all matching records

The CSV includes:
- Employee Name
- Employee Code
- Device
- Punch Type (Check In / Check Out)
- Timestamp
- Cloud Synced (Yes / No)

The export respects the same filters applied to the attendance list, so you can export specific date ranges or specific employees.

---

## 10. Disconnecting / Reconfiguring

### Remove Cloud Server

1. Go to **Cloud Servers** in the sidebar
2. Click the **Delete** button (trash icon)
3. Confirm deletion

This removes the cloud server configuration and clears the branch association. Attendance data remains stored locally.

### Reconfigure Cloud Connection

1. On the **Cloud Servers** page, simply update the API URL or API Key
2. Click **Test Connection** to verify
3. Click **Fetch Branches** if you need to select a different branch
4. Click **Save Configuration**

### Change Branch

If you move zpush to a different physical branch:

1. Go to **Cloud Servers**
2. Click **Fetch Branches**
3. Select the new branch
4. Save — future employee syncs will use the new branch's employees

### Revoke API Token (from lavloss)

If you need to revoke access for a zpush instance:

```bash
cd /path/to/lavloss
php artisan tinker
```

```php
$user = \App\Models\User::where('email', 'admin@example.com')->first();

// List all tokens
$user->tokens->each(fn($t) => dump($t->id, $t->name));

// Delete a specific token
$user->tokens()->where('name', 'zpush-main-factory')->delete();
```

After revoking, zpush will show "Auth failed — check API key" and stop syncing until a new token is configured.

---

## 11. Offline Mode

zpush is designed to work **fully offline**:

- **Device polling continues** — Attendance data is collected from the ZKTeco device even without internet
- **Data stored in SQLite** — All attendance logs, employees, and queue items persist locally
- **Queue system** — When internet returns, all pending sync items are automatically processed
- **No data loss** — The queue holds items with retry logic and backoff delays

### What Works Offline
- Device connection, testing, and polling
- Viewing attendance logs and employees
- Exporting attendance to CSV
- All local data management

### What Requires Cloud
- Pushing attendance to lavloss
- Pulling employee data from lavloss
- Branch selection during setup

---

## 12. Troubleshooting

### Connection Issues

| Problem                       | Check                                                              |
| ----------------------------- | ------------------------------------------------------------------ |
| "Device unreachable"          | Verify device IP, ensure same LAN, check device is powered on      |
| "Cloud connection failed"     | Check API URL (HTTPS?), verify API token, check lavloss is running |
| "Auth failed — check API key" | Token may be revoked. Create a new token in lavloss                |
| "Timeout"                     | Network issue. Check firewall rules, VPN connections               |

### Sync Issues

| Problem                    | Solution                                                                     |
| -------------------------- | ---------------------------------------------------------------------------- |
| Records stuck as "pending" | Check cloud server connection, try manual Sync Now                           |
| "No employees found"       | Verify branch selection, check employees exist in lavloss for that branch    |
| Duplicate attendance       | zpush deduplicates by UNIQUE constraint — duplicates are silently skipped    |
| Queue items failing        | Check error messages in Sync Monitor, look for specific employee/date issues |

### Running Diagnostics

```bash
# Check device connection from terminal
php artisan device:test --debug

# View recent logs
php artisan pail

# Check sync queue status
php artisan tinker
>>> \App\Models\SyncQueue::where('status', 'failed')->get()
```

### Getting Help

- Check the **Sync Monitor** for error messages on failed sync logs
- Failed sync entries show the specific error message inline
- Use the date and status filters to find recent failures
