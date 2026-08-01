#!/usr/bin/env python3
"""
ZKTeco device probe — answers one question per device:

  "Can this device push real-time events, and over which transport?"

It does NOT try to be a sync agent. It is a diagnostic you run once per
device, on the Windows box that will host the agent, and paste the output.

Usage:
    pip install pyzk
    python zk_probe.py 192.168.1.201
    python zk_probe.py 192.168.1.201 --password 0 --seconds 60

While the live-capture phase is running, GO PRESS A FINGER on the device.
That is the whole point of the test.
"""

import argparse
import socket
import struct
import sys
import time
from datetime import datetime

try:
    from zk import ZK, const
except ImportError:
    sys.exit("pyzk is not installed.  Run:  pip install pyzk")


# ----------------------------------------------------------------- utilities

def say(msg=""):
    print(msg, flush=True)


def head(title):
    say()
    say("=" * 68)
    say(f"  {title}")
    say("=" * 68)


def kv(key, value):
    say(f"  {key:<22} {value}")


def stamp():
    return datetime.now().strftime("%H:%M:%S.%f")[:-3]


# ------------------------------------------------------------------- phase 1

def probe_reachability(ip, port):
    """Is the device even listening on TCP 4370? UDP can't be probed this way."""
    head("PHASE 1 — Reachability")
    kv("Target", f"{ip}:{port}")

    s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    s.settimeout(3)
    try:
        s.connect((ip, port))
        kv("TCP port open", "YES")
        tcp_open = True
    except Exception as e:
        kv("TCP port open", f"NO ({e})")
        tcp_open = False
    finally:
        s.close()

    if not tcp_open:
        say()
        say("  NOTE: no TCP listener. This device is UDP-only, which is the")
        say("        firmware class where real-time push usually does not exist.")
    return tcp_open


# ------------------------------------------------------------------- phase 2

def connect(ip, port, password, force_udp, timeout):
    """Return a connected pyzk handle, or None."""
    zk = ZK(
        ip,
        port=port,
        timeout=timeout,
        password=password,
        force_udp=force_udp,
        ommit_ping=True,   # pyzk's spelling, not a typo on our side
        verbose=False,
    )
    conn = zk.connect()
    return conn


def probe_identity(conn, transport):
    """Everything that identifies the firmware generation."""
    head(f"PHASE 2 — Device identity over {transport}")

    facts = [
        ("Firmware version", conn.get_firmware_version),
        ("Platform", conn.get_platform),
        ("Device name", conn.get_device_name),
        ("Serial number", conn.get_serialnumber),
        ("MAC address", conn.get_mac),
        ("Device time", conn.get_time),
    ]
    for label, fn in facts:
        try:
            kv(label, fn())
        except Exception as e:
            kv(label, f"<unsupported: {e}>")

    try:
        conn.read_sizes()
        kv("Users", conn.users)
        kv("Fingerprints", conn.fingers)
        kv("Attendance records", conn.records)
    except Exception as e:
        kv("Sizes", f"<unsupported: {e}>")


# ------------------------------------------------------------------- phase 3

def probe_reg_event(conn):
    """
    Does the device ACCEPT the real-time event registration at all?

    This separates the two very different failure modes:
      A. device refuses CMD_REG_EVENT  -> firmware has no push, full stop
      B. device ACKs but never sends   -> push exists but transport is wrong
    Only B is fixable by changing language/library.
    """
    head("PHASE 3 — CMD_REG_EVENT acceptance")
    try:
        conn.reg_event(const.EF_ATTLOG)
        kv("Registration ACK", "YES  -> device accepted the subscription")
        return True
    except Exception as e:
        kv("Registration ACK", f"NO   -> {e}")
        say()
        say("  VERDICT: this firmware does not implement real-time push.")
        say("           No library in any language can change this.")
        return False


# ------------------------------------------------------------------- phase 4

def probe_live_capture(conn, seconds):
    head(f"PHASE 4 — Live capture, {seconds}s window")
    say("  >>> GO PRESS A FINGER ON THE DEVICE NOW <<<")
    say()

    started = time.time()
    events = 0
    idle_ticks = 0

    try:
        for att in conn.live_capture(new_timeout=1):
            if time.time() - started >= seconds:
                conn.end_live_capture = True
                break

            if att is None:
                idle_ticks += 1
                if idle_ticks % 10 == 0:
                    say(f"  [{stamp()}] ...{int(time.time() - started)}s, nothing yet")
                continue

            events += 1
            lag = "?"
            try:
                lag = f"{(datetime.now() - att.timestamp).total_seconds():.1f}s"
            except Exception:
                pass
            say(f"  [{stamp()}] EVENT  uid={att.user_id}  "
                f"time={att.timestamp}  status={att.status}  punch={att.punch}  "
                f"lag={lag}")
    except KeyboardInterrupt:
        say("  interrupted")
    except Exception as e:
        say(f"  live_capture raised: {type(e).__name__}: {e}")

    say()
    kv("Events received", events)
    return events


# ------------------------------------------------------------------- phase 5

def probe_polling(conn, seconds):
    """
    The fallback path. If push is dead, how fast and how expensive is it to
    just ask the device for its log repeatedly?
    """
    head(f"PHASE 5 — Polling fallback, {seconds}s window")
    say("  >>> PRESS A FINGER AGAIN <<<")
    say()

    try:
        baseline = conn.get_attendance()
    except Exception as e:
        say(f"  get_attendance failed: {e}")
        return

    kv("Baseline record count", len(baseline))
    seen = {(a.user_id, a.timestamp) for a in baseline}

    started = time.time()
    detections = 0
    durations = []

    while time.time() - started < seconds:
        t0 = time.time()
        try:
            rows = conn.get_attendance()
        except Exception as e:
            say(f"  [{stamp()}] poll failed: {e}")
            time.sleep(2)
            continue
        durations.append(time.time() - t0)

        for a in rows:
            key = (a.user_id, a.timestamp)
            if key not in seen:
                seen.add(key)
                detections += 1
                say(f"  [{stamp()}] NEW    uid={a.user_id}  time={a.timestamp}  "
                    f"detected {(datetime.now() - a.timestamp).total_seconds():.1f}s "
                    f"after the punch")
        time.sleep(2)

    say()
    kv("New records detected", detections)
    if durations:
        kv("get_attendance() cost", f"min {min(durations):.2f}s  "
                                    f"avg {sum(durations)/len(durations):.2f}s  "
                                    f"max {max(durations):.2f}s")
        kv("Records on device", len(seen))
        say()
        say("  If avg cost stays under ~1s, a 2-second poll loop gives you")
        say("  sub-3-second latency and is indistinguishable from push in")
        say("  practice — without depending on firmware that may not have it.")


# ----------------------------------------------------------------------- main

def run(ip, port, password, force_udp, seconds, timeout, skip_poll):
    transport = "UDP" if force_udp else "TCP"

    try:
        conn = connect(ip, port, password, force_udp, timeout)
    except Exception as e:
        head(f"CONNECT over {transport}")
        kv("Result", f"FAILED — {type(e).__name__}: {e}")
        return None

    head(f"CONNECT over {transport}")
    kv("Result", "OK")

    result = {"transport": transport, "connected": True,
              "reg_event": False, "events": 0}
    try:
        probe_identity(conn, transport)

        result["reg_event"] = probe_reg_event(conn)
        if result["reg_event"]:
            result["events"] = probe_live_capture(conn, seconds)

        if not skip_poll:
            probe_polling(conn, seconds)
    finally:
        try:
            conn.disconnect()
        except Exception:
            pass

    return result


def verdict(results):
    head("VERDICT")
    if not results:
        say("  Could not connect over any transport. Check IP, port 4370,")
        say("  Windows firewall, and the device's comm password.")
        return

    pushing = [r for r in results if r["events"] > 0]
    acked = [r for r in results if r["reg_event"]]

    for r in results:
        say(f"  {r['transport']:<4} connect=OK  reg_event={'YES' if r['reg_event'] else 'NO ':<3}  "
            f"events={r['events']}")
    say()

    if pushing:
        t = ", ".join(r["transport"] for r in pushing)
        say(f"  Real-time push WORKS over {t}.")
        say("  -> Configure this device for that transport and use live capture.")
    elif acked:
        say("  Device ACKs the subscription but sent nothing during the window.")
        say("  -> Either no finger was pressed, or the firmware ACKs and lies.")
        say("     Re-run and be sure to punch. If still zero: treat as no-push.")
    else:
        say("  Device has NO real-time push on any transport.")
        say("  -> This is a firmware limitation, not a PHP limitation.")
        say("     Rewriting in Python or Rust will NOT change this result.")
        say("     Use the polling path for this device.")


def main():
    p = argparse.ArgumentParser(description="ZKTeco real-time capability probe")
    p.add_argument("ip")
    p.add_argument("--port", type=int, default=4370)
    p.add_argument("--password", type=int, default=0)
    p.add_argument("--seconds", type=int, default=30,
                   help="length of the live-capture and polling windows")
    p.add_argument("--timeout", type=int, default=10, help="socket timeout")
    p.add_argument("--tcp-only", action="store_true")
    p.add_argument("--udp-only", action="store_true")
    p.add_argument("--skip-poll", action="store_true")
    args = p.parse_args()

    say(f"zk_probe — {datetime.now():%Y-%m-%d %H:%M:%S}")
    probe_reachability(args.ip, args.port)

    transports = []
    if not args.udp_only:
        transports.append(False)   # TCP
    if not args.tcp_only:
        transports.append(True)    # UDP

    results = []
    for force_udp in transports:
        r = run(args.ip, args.port, args.password, force_udp,
                args.seconds, args.timeout, args.skip_poll)
        if r:
            results.append(r)

    verdict(results)


if __name__ == "__main__":
    main()
