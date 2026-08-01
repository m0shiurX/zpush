//! Rust counterpart of zk_probe.py, using the `zkteco` crate (a port of pyzk).
//!
//! Run it against the SAME device, right after the Python probe, so you can
//! compare like for like:
//!
//!     cargo run --release -- 192.168.1.201
//!     cargo run --release -- 192.168.1.201 --udp --seconds 60
//!
//! Press a finger on the device during the live-capture window.

use std::env;
use std::time::{Duration, Instant};

use zkteco::Zk;

struct Args {
    ip: String,
    port: u16,
    password: u32,
    seconds: u64,
    udp: bool,
}

fn parse_args() -> Args {
    let mut a = Args {
        ip: String::new(),
        port: 4370,
        password: 0,
        seconds: 30,
        udp: false,
    };

    let argv: Vec<String> = env::args().skip(1).collect();
    let mut i = 0;
    while i < argv.len() {
        match argv[i].as_str() {
            "--udp" => a.udp = true,
            "--port" => { i += 1; a.port = argv[i].parse().expect("bad --port"); }
            "--password" => { i += 1; a.password = argv[i].parse().expect("bad --password"); }
            "--seconds" => { i += 1; a.seconds = argv[i].parse().expect("bad --seconds"); }
            other => a.ip = other.to_string(),
        }
        i += 1;
    }

    if a.ip.is_empty() {
        eprintln!("usage: zk-probe <ip> [--port 4370] [--password 0] [--seconds 30] [--udp]");
        std::process::exit(2);
    }
    a
}

fn head(title: &str) {
    println!("\n{}", "=".repeat(68));
    println!("  {title}");
    println!("{}", "=".repeat(68));
}

fn main() {
    let args = parse_args();
    let transport = if args.udp { "UDP" } else { "TCP" };

    head(&format!("CONNECT over {transport}"));
    println!("  target                 {}:{}", args.ip, args.port);

    let mut zk = Zk::builder(args.ip.clone())
        .port(args.port)
        .password(args.password)
        .force_udp(args.udp)
        .omit_ping(true)
        .timeout(Duration::from_secs(10))
        .build();

    if let Err(e) = zk.connect() {
        println!("  result                 FAILED — {e}");
        std::process::exit(1);
    }
    println!("  result                 OK");

    head(&format!("Device identity over {transport}"));
    macro_rules! fact {
        ($label:expr, $call:expr) => {
            match $call {
                Ok(v) => println!("  {:<22} {}", $label, v),
                Err(e) => println!("  {:<22} <unsupported: {}>", $label, e),
            }
        };
    }
    fact!("Firmware version", zk.get_firmware_version());
    fact!("Platform", zk.get_platform());
    fact!("Device name", zk.get_device_name());
    fact!("Serial number", zk.get_serialnumber());
    fact!("Device time", zk.get_time());

    match zk.read_sizes() {
        Ok(s) => println!("  {:<22} {:?}", "Sizes", s),
        Err(e) => println!("  {:<22} <unsupported: {}>", "Sizes", e),
    }

    head(&format!("Live capture over {transport}, {}s window", args.seconds));
    println!("  >>> GO PRESS A FINGER ON THE DEVICE NOW <<<\n");

    let started = Instant::now();
    let window = Duration::from_secs(args.seconds);
    let mut events = 0u32;

    match zk.live_capture(Duration::from_secs(1)) {
        Err(e) => {
            println!("  live_capture refused: {e}");
            println!("  -> the device rejected CMD_REG_EVENT: no real-time push in firmware.");
        }
        Ok(mut capture) => {
            for item in &mut capture {
                if started.elapsed() >= window {
                    capture.stop();
                    break;
                }
                match item {
                    Ok(Some(att)) => {
                        events += 1;
                        println!(
                            "  EVENT  uid={}  user_id={}  time={}  status={}  punch={}",
                            att.uid, att.user_id, att.timestamp, att.status, att.punch
                        );
                    }
                    Ok(None) => {
                        let secs = started.elapsed().as_secs();
                        if secs > 0 && secs % 10 == 0 {
                            println!("  ...{secs}s, nothing yet");
                        }
                    }
                    Err(e) => println!("  stream error: {e}"),
                }
            }
        }
    }

    println!("\n  Events received        {events}");

    head("Polling fallback cost");
    let t0 = Instant::now();
    match zk.get_attendance() {
        Ok(rows) => {
            println!("  {:<22} {}", "Records on device", rows.len());
            println!("  {:<22} {:.2}s", "get_attendance() took", t0.elapsed().as_secs_f64());
        }
        Err(e) => println!("  get_attendance failed: {e}"),
    }

    head("VERDICT");
    if events > 0 {
        println!("  Real-time push WORKS over {transport} from Rust.");
    } else {
        println!("  No events over {transport}. Compare against the Python run:");
        println!("  if pyzk also saw zero, the firmware has no push and the");
        println!("  language choice is irrelevant to this problem.");
    }

    let _ = zk.disconnect();
}
