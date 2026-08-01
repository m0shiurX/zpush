# Domain glossary

The words this project uses, and what they mean precisely. When a term here is
ambiguous in conversation, this file is the authority.

See `docs/plan.md` for architecture and `docs/adr/` for decisions.

---

## Device identity

A ZKTeco device carries **two** identifiers for every enrolled user. They are
not interchangeable, and conflating them has already caused one defect.

### device user id

The ID an operator sees on the terminal and gives to staff. A **string** on the
wire (24 bytes, null-padded). This is what attendance records carry, and it is
what employee matching keys on.

Stored as `employees.device_user_id` and `attendance_logs.device_user_id`.

### device slot uid

An internal slot number the device assigns when a user is enrolled. Only
commands that address a slot need it: set user, delete user, read fingerprint.
It is never used to identify a person.

Stored as `employees.device_slot_uid`.

**They diverge in practice.** A user enrolled at the keypad on our K40 held slot
`1` and device user id `101`. Bulk polling used to key on the slot while the
real-time listener keyed on the user id, so the same punch could be stored twice
under two different keys and dedupe could not see it. For employees this
application enrols, the two are deliberately set to the same number — which is
why the bug stayed invisible until a device with keypad enrolments turned up.

---

## The attendance log

### log ordinal

A record's position in the device's attendance log, counting from 0. The log is
**append-only** and the device returns it in **insertion order**.

Insertion order is not time order. A device that loses power restarts its clock
at 2000-01-01, so year-2000 stamps appear *after* 2026 ones in the same log. Our
K40's log does exactly this at positions 51–52.

### high-water mark

How far through a device's log we have already synced, stored as
`device_configs.last_synced_ordinal`. Keyed on **ordinal, never on timestamp** —
a timestamp watermark would silently skip every punch made after a clock reset.

`device_configs.last_record_count` exists alongside it to notice a wipe: if the
device reports fewer records than last time, its log was cleared, positions have
been reused, and the mark resets to 0.

### punch

The `punch` byte on an attendance record: check-in, check-out, break, overtime.
Modelled by `App\Enums\PunchType`. Distinct from **status**, which records *how*
the person identified themselves (fingerprint, password, card).

### quarantine

A record stored but withheld from cloud sync because its device timestamp cannot
be believed — specifically, anything stamped before **2001-01-01**. ZKTeco counts
time from 2000-01-01 and a device that loses power restarts there, so the whole
of that first year is clock-reset wreckage; real deployments have no attendance
from 2000.

Quarantined records are neither dropped nor pushed. They are real events with
meaningless times, so a human decides what they mean. `AttendanceLog::unsynced()`
excludes them; `AttendanceLog::quarantined()` finds them.

The threshold is deliberately **not** the device's `created_at`: a device
registered today may still hold a legitimate backlog, and quarantining all of it
would be worse than useless.

---

## Reading from the device

### buffered read

The protocol for pulling a large dataset off a device: stage it with
`CMD_PREPARE_BUFFER` (1503), then pull it down in chunks with `CMD_READ_BUFFER`
(1504). Implemented by `App\Services\Zk\BufferedReader`.

This is the *only* path we use for bulk data. The vendored library's approach —
a bare `CMD_ATT_LOG_RRQ` read through `CMD_PREPARE_DATA` — returns the wrong
dataset entirely on K40 firmware 6.60. See
[ADR 0001](adr/0001-php-buffered-read.md).

### pushed packet

A packet the device sends without being asked, during a buffered read. Read with
`ZkTransport::readPacket()`, never `command()`.

Two things about pushed packets have already broken this code:

1. They carry reply ids that must **not** advance the command sequence.
2. The run of them ends with a `CMD_ACK_OK` that **must** be consumed. Leaving it
   on the wire puts every later reply on that connection off by one, so the
   connection appears alive while returning empty answers to everything.

### real-time capture

A separate mechanism: `CMD_REG_EVENT` subscribes to punches as they happen. This
works on our hardware and is unaffected by the bulk-read defect. Bulk reading and
real-time listening cannot share a connection — see
`App\Services\ListenerCoordinator`.
