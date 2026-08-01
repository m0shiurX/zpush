# 1. Read bulk device data with the buffered protocol

- **Status:** Accepted
- **Date:** 2026-08-01
- **Hardware under test:** K40/ID, platform JZ4725_TFT, firmware Ver 6.60
  (Jun 9 2017), serial A8N5181260514, at 192.168.68.13:4370

## Context

Reading historical attendance off the device did not work. Real-time capture did.
The working assumption was that this was a PHP limitation, and the proposed fix
was to rewrite the device agent — and possibly the whole application — in Python
or Rust.

We measured it instead.

| Path | Result |
|---|---|
| pyzk, TCP, `get_attendance()` | **65 rows** |
| pyzk, UDP, `connect()` | **timed out** — this device is TCP-only |
| `0mithun/php-zkteco`, TCP, `getAttendances()` | **0 rows** |
| `0mithun/php-zkteco`, TCP, `getFingerprint()` | **0 entries**, for every uid |

Same device, same minute. Small replies worked in PHP (users, real-time capture);
**large payloads returned nothing**.

### The defect

The network layer was fine — 2132 bytes came back. The request flow was wrong.
`Attendance::get()` issues a bare `CMD_ATT_LOG_RRQ` (13) and reads the reply
through `CMD_PREPARE_DATA` / `CMD_DATA`. On this firmware the device answers that
request with **the user table, not the attendance table**:

```
   0  dd056395000001004808000040d83580  ..c.....H...@.5.
  16  0000000000000041425520484153414e  .......ABU HASAN
  96  617a6d756c2048617175650000000000  azmul Haque.....
 160  0000000000000053756d6f6e20486f73  .......Sumon Hos
```

Those are 72-byte user records (names at offsets 23, 95, 167). The parser reads
them as 40-byte attendance records, every field lands on garbage, the
`$rawTimestamp === 0 || $userId <= 0` guard rejects all of them, and the caller
receives `[]` — indistinguishable from a device with nothing to report.

Call orders that do **not** help, all verified: calling twice in one session,
`getUsers()` first, `disableDevice()` first.

pyzk never takes that path. Over TCP it stages the dataset with
`CMD_PREPARE_BUFFER` (1503) and pulls it in chunks with `CMD_READ_BUFFER` (1504).

### What this rules out

The Rust crate `zkteco` v1.0.0 describes itself as *"a faithful port of the
Python pyzk library"* (51 total downloads at time of writing). Same protocol
logic, same failure modes. Neither language choice was ever the constraint.

## Decision

Keep PHP. Serve bulk reads through the buffered protocol, implemented in `app/`
rather than as a vendor patch, so `composer update` cannot quietly reintroduce
the defect.

- `App\Services\Zk\BufferedReader` — the 1503/1504 primitive
- `App\Services\Zk\ZkTransport` / `SocketTransport` — framing and session
  bookkeeping, behind an interface so recorded device bytes can drive it
- `App\Services\Zk\AttendanceParser` — pure bytes-to-records decode
- `App\Services\Zk\ZkClient extends ZKTeco` — everything else works as shipped

The parser **throws** on an incoherent payload rather than returning an empty
list. Failing loudly is the whole point: the original defect was silent.

### Two hazards found while building it

Both were discovered against real hardware, and both are locked down by the
recorded-exchange test:

1. **Pushed packets must not advance the reply sequence.** Absorbing their reply
   ids left the connection unable to issue any further command.
2. **The stream's closing `CMD_ACK_OK` must be consumed.** Leaving it on the wire
   put every later reply off by one — `deviceName()` returned empty and
   `getUsers()` returned 0 while the connection looked healthy.

### Related decisions

- **Identity keys on device user id, not the internal slot uid.** The two
  diverge on keypad-enrolled users, and the bulk and real-time paths disagreed
  about which they meant. See `docs/CONTEXT.md`.
- **The sync watermark is a log ordinal, not a timestamp.** The log is
  insertion-ordered and its timestamps are not monotonic across a clock reset.
- **Records stamped before 2001-01-01 are quarantined**, not dropped and not
  pushed.
- **The device clock is set on connect** when it drifts more than 60s.

## Consequences

Verified end to end against the device: **65 records read, 59 stored, 8
quarantined, 6 skipped as unknown users; a second poll stores nothing new.**
Before this change the same call returned 0.

### The watermark is held, not advanced, past unknown users

The mark stops at the first record whose `device_user_id` matches no employee,
because that employee may still arrive from the cloud and advancing past the
record would strand it forever. On the test device this parks the mark at
ordinal 13, so each poll re-examines 46 records and finds them all duplicates.

That is a deliberate trade: a bounded, repeated cost in exchange for never
silently losing a punch. If an unmatched device user is permanent, the cost is
permanent too — remove the user from the device, or enrol the employee.

### Known gaps

- **Only attendance is routed through the primitive.** `getUsers()` still uses
  the inherited vendor call — it works on this firmware — and `getFingerprint()`
  is untouched and still returns 0 entries. Fingerprint reads fail from the same
  root cause and should be routed next; `BufferedReader::read()` is already
  generic over the command.
- **Quarantined records are not surfaced in the UI.** They are stored, flagged
  and withheld from cloud sync, and `AttendanceLog::quarantined()` finds them,
  but nothing yet shows them to a human. The scope has no callers.

Also unfixed and known: `getTime()` returns `2000-01-01` when called after
`getMac()` — the same response-desynchronisation family, in a different command.
Called first after connect it is correct. We chose the narrow primitive over a
general socket-drain guard, so this remains an open issue.

The rewrite is deferred, not cancelled. It reopens only on a genuine protocol
dead end — a device pyzk or rustzk can talk to that a PHP port demonstrably
cannot. Deployment or packaging pain is a separate argument on a separate axis.

## Reproducing

`tools/zk-probe/` holds the diagnostic used here: a Python probe (pyzk) and a
Rust counterpart, plus measured results. `tests/Fixtures/zk/` holds the captured
device payload, the recorded transport exchange, and expected output generated by
pyzk's own decode algorithm applied to those exact bytes — so the tests run in CI
with no hardware.
