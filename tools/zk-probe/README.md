# zk-probe

A throwaway diagnostic, not a product.

## Measured results — device 192.168.68.13, 2026-08-01

| | |
|---|---|
| Model | K40/ID |
| Platform | JZ4725_TFT |
| Firmware | Ver 6.60 Jun 9 2017 |
| Serial | A8N5181260514 |
| Sizes | 8 users, 7 fingerprints, **52 attendance records**, capacity 80000 |

| Path | Result |
|---|---|
| pyzk, TCP, `get_attendance()` | **52 rows in 2.16s** |
| pyzk, UDP, `connect()` | **timed out** — device is TCP-only |
| `0mithun/php-zkteco`, TCP, `getAttendances()` | **0 rows** |

Same device, same minute. **The bulk-read bug is in the PHP library, and it is
reproducible on demand.** It is not a firmware limitation and not a PHP-language
limitation.

### Where the PHP library fails

The network layer is fine — 2132 bytes come back. The failure is in the request
flow. `Attendance::get()` issues a bare `CMD_ATT_LOG_RRQ` (13) and then reads via
the legacy `CMD_PREPARE_DATA` / `CMD_DATA` path. On this firmware the device
answers that request with the **user table, not the attendance table**:

```
   0  dd056395000001004808000040d83580  ..c.....H...@.5.
  16  0000000000000041425520484153414e  .......ABU HASAN
  32  204c41564c5500000000000000000028   LAVLU.........(
  ...
  96  617a6d756c2048617175650000000000  azmul Haque.....
 160  0000000000000053756d6f6e20486f73  .......Sumon Hos
```

Those are 72-byte user records (stride confirmed: names at offsets 23, 95, 167).
`Attendance::get()` then parses them as 40-byte attendance records, every field
lands on garbage, the `$rawTimestamp === 0 || $userId <= 0` guard rejects all of
them, and the caller gets `[]` with no error.

pyzk never takes that path. Over TCP it uses the buffered read —
`CMD_PREPARE_BUFFER` (1503) with a packed `<bhii` argument, then `CMD_READ_BUFFER`
(1504) in chunks — which returns the correct dataset.

Verified call orders that do **not** help: calling twice in one session,
`getUsers()` first, `disableDevice()` first. All return 0 rows.

### Also worth knowing

**The device clock is fine.** Called first thing after connect, `get_time()`
returns `2026-08-01 15:46:57` against a host time of `15:47:30` — 33 seconds slow.

But called *after* `get_mac()`, the same `get_time()` returns `2000-01-01
00:07:29`. That is a response/buffer desynchronisation: a preceding command
leaves unread bytes on the socket and the next reply is parsed from the wrong
packet. Same failure class as the attendance bug, different command. Worth
knowing before trusting any single reading from this library.

**8 of the 55 records really are stamped year 2000**, in two clusters (indices
0–5 and 51–52) — genuine power-loss episodes, not a dead RTC. The other 47 are
sane and monotonic.

**The dedupe key holds on real data.** Across all 55 records there are zero
collisions on `(user_id, timestamp)` and zero on
`(user_id, timestamp, status, punch)`.

**Device user identity is ambiguous.** `uid=1` appears as `user_id=101` in
records 13–20 and as `user_id=1` from record 38 on — the user-facing ID was
edited on the device while the internal uid stayed put. Anything keyed on the
wrong one of those two silently mismatches employees.

## Running it

### Python

```
pip install pyzk
python zk_probe.py 192.168.68.13 --seconds 60
```

Press a finger during the live-capture and polling phases or those phases prove
nothing.

### Rust

```
cargo run --release -- 192.168.68.13 --seconds 60
```

Uses the `zkteco` crate, which is a port of pyzk. Its purpose here is comparison
only. **Not compiled yet** — no Rust toolchain on this machine.

## Correction to the earlier analysis

An earlier pass flagged `socket_recvfrom($sock, $data, $len, 0, $self->_ip,
$self->_port)` in `Util.php` as the cause. That call does clobber `_ip`/`_port`
by reference, but it is on the **UDP** path, and this device does not answer UDP
at all — so it is not the bug being hit. The real-time listener also works, as
you said. The defect is the bulk-read request flow described above.
