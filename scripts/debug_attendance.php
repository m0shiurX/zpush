<?php

/**
 * Debug script: Hex-dump raw attendance data from ZKTeco device
 * to determine the actual record format/size.
 *
 * Usage: php artisan tinker scripts/debug_attendance.php
 */

use App\Models\DeviceConfig;
use App\Services\DeviceService;
use Mithun\PhpZkteco\Libs\Services\Util;

$device = DeviceConfig::first();
$service = new DeviceService;
$service->connect($device);

$zk = (new ReflectionClass($service))->getProperty('zk');
$zk->setAccessible(true);
$zkInstance = $zk->getValue($service);

// Send the attendance log request command — _command is public in 0mithun/php-zkteco
$command = Util::CMD_ATT_LOG_RRQ;
$session = $zkInstance->_command($command, '', Util::COMMAND_TYPE_DATA);

echo 'Session result: ' . var_export($session, true) . PHP_EOL;

if ($session === false) {
    echo 'FAILED to get session for attendance data' . PHP_EOL;
    $service->disconnect();
    exit(1);
}

// Read the raw bulk data via Util::recData (handles TCP framing automatically)
$rawData = Util::recData($zkInstance);

echo 'Raw data length: ' . strlen($rawData) . PHP_EOL;

if (strlen($rawData) <= 10) {
    echo 'No attendance data (too short)' . PHP_EOL;
    $service->disconnect();
    exit(0);
}

// Skip the 10-byte header (like the parser does)
$payload = substr($rawData, 10);
$len = strlen($payload);

echo 'Payload length (after 10-byte skip): ' . $len . PHP_EOL;

foreach ([8, 16, 24, 32, 40, 48, 56] as $candidate) {
    $rem = $len % $candidate;
    $count = intdiv($len, $candidate);
    echo "  Divisible by {$candidate}? " . ($rem === 0 ? "YES ({$count} records)" : "NO (rem={$rem})") . PHP_EOL;
}

echo PHP_EOL . '=== Full payload hex dump ===' . PHP_EOL;
for ($i = 0; $i < $len; $i++) {
    if ($i > 0 && $i % 16 === 0) {
        echo PHP_EOL;
    }
    if ($i % 16 === 0) {
        printf('%04x: ', $i);
    }
    printf('%02x ', ord($payload[$i]));
}
echo PHP_EOL;

echo PHP_EOL . '=== Also dump first 10 bytes (header that was skipped) ===' . PHP_EOL;
$header = substr($rawData, 0, 10);
for ($i = 0; $i < strlen($header); $i++) {
    printf('%02x ', ord($header[$i]));
}
echo PHP_EOL;

$service->disconnect();
echo 'Done.' . PHP_EOL;
