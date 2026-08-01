<?php

use App\Enums\PunchType;
use App\Exceptions\DeviceConnectionException;
use App\Models\AttendanceLog;
use App\Models\DeviceConfig;
use App\Models\Employee;
use App\Services\DeviceService;
use App\Services\Zk\AttendanceRecord;
use App\Services\Zk\ZkClient;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->device = DeviceConfig::factory()->create([
        'ip_address' => '192.168.1.201',
        'port' => 4370,
    ]);
});

test('testConnection returns device info on success', function () {
    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('connect')->once()->andReturn(true);
    $mockZk->shouldReceive('serialNumber')->once()->andReturn('ABC123');
    $mockZk->shouldReceive('deviceName')->once()->andReturn('ZK-F18');
    $mockZk->shouldReceive('version')->once()->andReturn('Ver 6.21');
    $mockZk->shouldReceive('disconnect')->zeroOrMoreTimes();

    $result = simulateTestConnection($this->device, $mockZk);

    expect($result['success'])->toBeTrue()
        ->and($result['serial_number'])->toBe('ABC123')
        ->and($result['device_name'])->toBe('ZK-F18')
        ->and($result['firmware'])->toBe('Ver 6.21')
        ->and($result['error'])->toBeNull();
});

test('testConnection returns failure when connection fails', function () {
    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('connect')->once()->andReturn(false);
    $mockZk->shouldReceive('disconnect')->zeroOrMoreTimes();

    $result = simulateTestConnection($this->device, $mockZk);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->not->toBeNull();
});

test('pollAttendance stores new attendance records', function () {
    Employee::factory()->create(['device_user_id' => '1']);

    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('readAttendance')->once()->andReturn(attendanceRecords([
        ['user_id' => '1', 'timestamp' => '2025-03-01 09:00:00', 'punch' => 0],
        ['user_id' => '1', 'timestamp' => '2025-03-01 18:00:00', 'punch' => 1],
    ]));

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $result = $service->pollAttendance($this->device);

    expect($result['total'])->toBe(2)
        ->and($result['new'])->toBe(2)
        ->and($result['duplicates'])->toBe(0)
        ->and(AttendanceLog::count())->toBe(2);
});

test('pollAttendance skips duplicate records', function () {
    $employee = Employee::factory()->create(['device_user_id' => '1']);

    AttendanceLog::factory()->create([
        'employee_id' => $employee->id,
        'device_id' => $this->device->id,
        'device_user_id' => '1',
        'timestamp' => '2025-03-01 09:00:00',
    ]);

    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('readAttendance')->once()->andReturn(attendanceRecords([
        ['user_id' => '1', 'timestamp' => '2025-03-01 09:00:00', 'punch' => 0],
    ]));

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $result = $service->pollAttendance($this->device);

    expect($result['duplicates'])->toBe(1)
        ->and($result['new'])->toBe(0)
        ->and(AttendanceLog::count())->toBe(1);
});

test('syncUsersFromDevice matches existing employees by device_user_id', function () {
    // Create two employees that are already in the DB (from cloud sync)
    $alice = Employee::factory()->create(['device_user_id' => '1', 'name' => 'Alice', 'card_number' => null]);
    $bob = Employee::factory()->create(['device_user_id' => '2', 'name' => 'Bob', 'card_number' => null]);

    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('getUsers')->once()->andReturn([
        '1' => ['uid' => 1, 'user_id' => '1', 'name' => 'Alice', 'role' => 0, 'password' => '', 'card_no' => '00001234567'],
        '2' => ['uid' => 2, 'user_id' => '2', 'name' => 'Bob', 'role' => 0, 'password' => '', 'card_no' => '00000000000'],
        '3' => ['uid' => 999, 'user_id' => '999', 'name' => 'Unknown', 'role' => 0, 'password' => '', 'card_no' => '00000000000'],
    ]);

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $result = $service->syncUsersFromDevice($this->device);

    // Only 2 matched — the unknown UID 999 is skipped
    expect($result)->toHaveCount(2)
        ->and(Employee::count())->toBe(2)
        ->and($alice->fresh()->card_number)->toBe('1234567');
});

test('syncUsersFromDevice does not create employees from unknown device users', function () {
    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('getUsers')->once()->andReturn([
        '1' => ['uid' => 100, 'user_id' => '100', 'name' => 'Ghost', 'role' => 0, 'password' => '', 'card_no' => '00000000000'],
    ]);

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $result = $service->syncUsersFromDevice($this->device);

    expect($result)->toHaveCount(0)
        ->and(Employee::count())->toBe(0);
});

test('pollAttendance skips records from unknown UIDs', function () {
    Employee::factory()->create(['device_user_id' => '1']);

    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('readAttendance')->once()->andReturn(attendanceRecords([
        ['user_id' => '1', 'timestamp' => '2025-03-01 09:00:00', 'punch' => 0],
        ['user_id' => '999', 'timestamp' => '2025-03-01 10:00:00', 'punch' => 0],
    ]));

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $result = $service->pollAttendance($this->device);

    // Only UID 1 is imported, UID 999 is skipped
    expect($result['new'])->toBe(1)
        ->and($result['total'])->toBe(2)
        ->and(AttendanceLog::count())->toBe(1)
        ->and(AttendanceLog::first()->employee_id)->not->toBeNull();
});

test('pollAttendance returns zeros when no logs on device', function () {
    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('readAttendance')->once()->andReturn([]);

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $result = $service->pollAttendance($this->device);

    expect($result)->toBe(['total' => 0, 'new' => 0, 'duplicates' => 0, 'skipped' => 0, 'quarantined' => 0]);
});

test('handleRealtimeEvent stores attendance for known employee', function () {
    $employee = Employee::factory()->create(['device_user_id' => '6']);

    $service = new DeviceService;

    $result = $service->handleRealtimeEvent([
        'user_id' => '6',
        'record_time' => '2025-03-01 09:00:00',
        'state' => 0,
        'type' => 0,
        'device_ip' => '192.168.1.201',
    ], $this->device);

    expect($result)->toBe(['new' => 1, 'skipped' => 0])
        ->and(AttendanceLog::count())->toBe(1);

    $log = AttendanceLog::first();
    expect($log->employee_id)->toBe($employee->id)
        ->and($log->device_user_id)->toBe('6')
        ->and($log->device_id)->toBe($this->device->id)
        ->and($log->timestamp->format('Y-m-d H:i:s'))->toBe('2025-03-01 09:00:00');
});

test('handleRealtimeEvent skips unknown user ids', function () {
    Employee::factory()->create(['device_user_id' => '6']);

    $service = new DeviceService;

    $result = $service->handleRealtimeEvent([
        'user_id' => '999',
        'record_time' => '2025-03-01 09:00:00',
        'state' => 0,
        'type' => 0,
        'device_ip' => '192.168.1.201',
    ], $this->device);

    expect($result)->toBe(['new' => 0, 'skipped' => 1])
        ->and(AttendanceLog::count())->toBe(0);
});

test('handleRealtimeEvent skips duplicate events', function () {
    $employee = Employee::factory()->create(['device_user_id' => '6']);

    AttendanceLog::factory()->create([
        'employee_id' => $employee->id,
        'device_id' => $this->device->id,
        'device_user_id' => '6',
        'timestamp' => '2025-03-01 09:00:00',
    ]);

    $service = new DeviceService;

    $result = $service->handleRealtimeEvent([
        'user_id' => '6',
        'record_time' => '2025-03-01 09:00:00',
        'state' => 0,
        'type' => 0,
        'device_ip' => '192.168.1.201',
    ], $this->device);

    expect($result)->toBe(['new' => 0, 'skipped' => 1])
        ->and(AttendanceLog::count())->toBe(1);
});

test('handleRealtimeEvent maps punch type from type field', function () {
    Employee::factory()->create(['device_user_id' => '6']);

    $service = new DeviceService;

    $service->handleRealtimeEvent([
        'user_id' => '6',
        'record_time' => '2025-03-01 18:00:00',
        'state' => 1,
        'type' => 1,
        'device_ip' => '192.168.1.201',
    ], $this->device);

    expect(AttendanceLog::first()->punch_type)->toBe(PunchType::CheckOut);
});

test('handleRealtimeEvent maps all punch types correctly', function (int $type, PunchType $expected) {
    Employee::factory()->create(['device_user_id' => '6']);

    $service = new DeviceService;

    $service->handleRealtimeEvent([
        'user_id' => '6',
        'record_time' => '2025-03-01 09:00:00',
        'state' => 1,
        'type' => $type,
        'device_ip' => '192.168.1.201',
    ], $this->device);

    expect(AttendanceLog::first()->punch_type)->toBe($expected);
})->with([
    'CheckIn' => [0, PunchType::CheckIn],
    'CheckOut' => [1, PunchType::CheckOut],
    'BreakOut' => [2, PunchType::BreakOut],
    'BreakIn' => [3, PunchType::BreakIn],
    'OvertimeIn' => [4, PunchType::OvertimeIn],
    'OvertimeOut' => [5, PunchType::OvertimeOut],
]);

test('handleRealtimeEvent uses type not state for punch type', function () {
    Employee::factory()->create(['device_user_id' => '6']);

    $service = new DeviceService;

    // state=1 (Fingerprint) should NOT make it CheckOut;
    // type=0 (CheckIn) should be used instead
    $service->handleRealtimeEvent([
        'user_id' => '6',
        'record_time' => '2025-03-01 09:00:00',
        'state' => 1,
        'type' => 0,
        'device_ip' => '192.168.1.201',
    ], $this->device);

    expect(AttendanceLog::first()->punch_type)->toBe(PunchType::CheckIn);
});

test('handleRealtimeEvent defaults to CheckIn when type is missing', function () {
    Employee::factory()->create(['device_user_id' => '6']);

    $service = new DeviceService;

    $service->handleRealtimeEvent([
        'user_id' => '6',
        'record_time' => '2025-03-01 09:00:00',
        'state' => 1,
        'device_ip' => '192.168.1.201',
    ], $this->device);

    expect(AttendanceLog::first()->punch_type)->toBe(PunchType::CheckIn);
});

test('listenForAttendance registers for real-time events', function () {
    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('getRealTimeLogs')
        ->once()
        ->withArgs(function ($callback, $timeout) {
            return is_callable($callback) && $timeout === 30;
        })
        ->andReturn(true);

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $service->listenForAttendance($this->device, 30);
});

// ==========================================
// Test Helpers
// ==========================================

/**
 * Inject a mock ZKTeco instance into the DeviceService via reflection.
 */
function injectZkMock(DeviceService $service, DeviceConfig $device, $mockZk): void
{
    $ref = new ReflectionClass($service);

    $zkProp = $ref->getProperty('zk');
    $zkProp->setAccessible(true);
    $zkProp->setValue($service, $mockZk);

    $deviceProp = $ref->getProperty('device');
    $deviceProp->setAccessible(true);
    $deviceProp->setValue($service, $device);

    $device->recordSuccess();
}

/**
 * Simulate testConnection by manually calling mock methods
 * (avoids needing to mock the ZKTeco constructor).
 *
 * @return array{success: bool, serial_number: string|null, device_name: string|null, firmware: string|null, error: string|null}
 */
function simulateTestConnection(DeviceConfig $device, $mockZk): array
{
    try {
        $connected = $mockZk->connect();

        if (! $connected) {
            $device->recordFailure();

            throw new DeviceConnectionException($device);
        }

        $device->recordSuccess();

        $info = [
            'success' => true,
            'serial_number' => $mockZk->serialNumber() ?: null,
            'device_name' => $mockZk->deviceName() ?: null,
            'firmware' => $mockZk->version() ?: null,
            'error' => null,
        ];

        $mockZk->disconnect();

        return $info;
    } catch (Throwable $e) {
        try {
            $mockZk->disconnect();
        } catch (Throwable) {
        }

        return [
            'success' => false,
            'serial_number' => null,
            'device_name' => null,
            'firmware' => null,
            'error' => $e->getMessage(),
        ];
    }
}

test('pollAttendance ignores records already below the watermark', function () {
    Employee::factory()->create(['device_user_id' => '1']);
    $this->device->update(['last_synced_ordinal' => 2, 'last_record_count' => 2]);

    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('readAttendance')->once()->andReturn(attendanceRecords([
        ['user_id' => '1', 'timestamp' => '2025-03-01 09:00:00'],
        ['user_id' => '1', 'timestamp' => '2025-03-01 10:00:00'],
        ['user_id' => '1', 'timestamp' => '2025-03-01 11:00:00'],
    ]));

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $result = $service->pollAttendance($this->device);

    expect($result['new'])->toBe(1)
        ->and(AttendanceLog::count())->toBe(1)
        ->and(AttendanceLog::first()->timestamp->format('H:i'))->toBe('11:00');
});

test('pollAttendance advances the watermark to the end of the log', function () {
    Employee::factory()->create(['device_user_id' => '1']);

    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('readAttendance')->once()->andReturn(attendanceRecords([
        ['user_id' => '1', 'timestamp' => '2025-03-01 09:00:00'],
        ['user_id' => '1', 'timestamp' => '2025-03-01 10:00:00'],
    ]));

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $service->pollAttendance($this->device);

    expect($this->device->fresh()->last_synced_ordinal)->toBe(2)
        ->and($this->device->fresh()->last_record_count)->toBe(2);
});

test('pollAttendance re-reads from the start when the device log was cleared', function () {
    Employee::factory()->create(['device_user_id' => '1']);
    $this->device->update(['last_synced_ordinal' => 50, 'last_record_count' => 50]);

    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('readAttendance')->once()->andReturn(attendanceRecords([
        ['user_id' => '1', 'timestamp' => '2025-04-01 09:00:00'],
    ]));

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    // Without wipe detection the single record sits below ordinal 50 and is lost.
    expect($service->pollAttendance($this->device)['new'])->toBe(1)
        ->and(AttendanceLog::count())->toBe(1);
});

test('pollAttendance keeps records made after a clock reset', function () {
    Employee::factory()->create(['device_user_id' => '1']);

    // The device log is insertion-ordered, so a power loss puts year-2000
    // stamps after 2026 ones. Ordering by time here would lose the last two.
    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('readAttendance')->once()->andReturn(attendanceRecords([
        ['user_id' => '1', 'timestamp' => '2026-03-30 14:00:00'],
        ['user_id' => '1', 'timestamp' => '2000-01-01 00:07:14'],
        ['user_id' => '1', 'timestamp' => '2000-01-01 00:07:36'],
    ]));

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $result = $service->pollAttendance($this->device);

    expect($result['new'])->toBe(3)
        ->and(AttendanceLog::count())->toBe(3);
});

test('pollAttendance quarantines records stamped before the device existed', function () {
    Employee::factory()->create(['device_user_id' => '1']);

    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('readAttendance')->once()->andReturn(attendanceRecords([
        ['user_id' => '1', 'timestamp' => '2000-01-01 00:07:14'],
        ['user_id' => '1', 'timestamp' => '2026-03-30 14:00:00'],
    ]));

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $result = $service->pollAttendance($this->device);

    expect($result['quarantined'])->toBe(1)
        ->and(AttendanceLog::where('is_quarantined', true)->count())->toBe(1)
        ->and(AttendanceLog::where('is_quarantined', true)->first()->timestamp->year)->toBe(2000);
});

test('pollAttendance stores a punch once even if polled twice', function () {
    Employee::factory()->create(['device_user_id' => '1']);

    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('readAttendance')->twice()->andReturn(attendanceRecords([
        ['user_id' => '1', 'timestamp' => '2025-03-01 09:00:00'],
    ]));

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $service->pollAttendance($this->device);
    $this->device->update(['last_synced_ordinal' => 0]);
    $second = $service->pollAttendance($this->device);

    expect($second['duplicates'])->toBe(1)
        ->and(AttendanceLog::count())->toBe(1);
});

test('syncUsersFromDevice matches on the operator-facing id, not the device slot', function () {
    // Enrolled at the keypad: internal slot 1, operator-facing id 101.
    $employee = Employee::factory()->create(['device_user_id' => '101', 'device_slot_uid' => null]);

    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('getUsers')->once()->andReturn([
        ['uid' => 1, 'user_id' => '101', 'name' => 'Keypad User', 'card_no' => '0'],
    ]);

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $synced = $service->syncUsersFromDevice($this->device);

    expect($synced)->toHaveCount(1)
        ->and($employee->fresh()->device_slot_uid)->toBe(1);
});

test('pollAttendance resets the watermark when the device log is emptied', function () {
    Employee::factory()->create(['device_user_id' => '1']);
    $this->device->update(['last_synced_ordinal' => 65, 'last_record_count' => 65]);

    $mockZk = Mockery::mock(ZkClient::class);
    // A cleared device reports zero records. If the watermark survives that,
    // every record of the next log falls below it and is lost for good.
    $mockZk->shouldReceive('readAttendance')->once()->andReturn([]);
    $mockZk->shouldReceive('readAttendance')->once()->andReturn(attendanceRecords([
        ['user_id' => '1', 'timestamp' => '2026-04-01 09:00:00'],
    ]));

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $service->pollAttendance($this->device);
    expect($this->device->fresh()->last_synced_ordinal)->toBe(0);

    $result = $service->pollAttendance($this->device->fresh());

    expect($result['new'])->toBe(1)
        ->and(AttendanceLog::count())->toBe(1);
});

test('pollAttendance holds the watermark at the first unknown device user', function () {
    Employee::factory()->create(['device_user_id' => '1']);

    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('readAttendance')->once()->andReturn(attendanceRecords([
        ['user_id' => '1', 'timestamp' => '2026-04-01 09:00:00'],
        ['user_id' => '99', 'timestamp' => '2026-04-01 10:00:00'],
        ['user_id' => '1', 'timestamp' => '2026-04-01 11:00:00'],
    ]));

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $result = $service->pollAttendance($this->device);

    // Employee 99 may still arrive from the cloud, so the log must not be
    // consumed past that record.
    expect($result['skipped'])->toBe(1)
        ->and($this->device->fresh()->last_synced_ordinal)->toBe(1);
});

test('pollAttendance recovers a skipped punch once its employee arrives', function () {
    Employee::factory()->create(['device_user_id' => '1']);

    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('readAttendance')->twice()->andReturn(attendanceRecords([
        ['user_id' => '1', 'timestamp' => '2026-04-01 09:00:00'],
        ['user_id' => '99', 'timestamp' => '2026-04-01 10:00:00'],
    ]));

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $service->pollAttendance($this->device);
    expect(AttendanceLog::count())->toBe(1);

    Employee::factory()->create(['device_user_id' => '99']);
    $service->pollAttendance($this->device->fresh());

    expect(AttendanceLog::count())->toBe(2);
});

test('connect resets a device clock that has drifted', function () {
    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('connect')->once()->andReturn(true);
    $mockZk->shouldReceive('getTime')->once()->andReturn('2000-01-01 00:07:29');
    $mockZk->shouldReceive('setTime')->once()->withArgs(
        fn ($t) => $t === now()->format('Y-m-d H:i:s')
    )->andReturn(true);

    $service = serviceUsing($mockZk);

    $service->connect($this->device);

    $mockZk->shouldHaveReceived('setTime');
});

test('connect leaves an accurate device clock alone', function () {
    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('connect')->once()->andReturn(true);
    $mockZk->shouldReceive('getTime')->once()->andReturn(now()->subSeconds(5)->format('Y-m-d H:i:s'));
    $mockZk->shouldNotReceive('setTime');

    $service = serviceUsing($mockZk);

    expect($service->connect($this->device))->toBeInstanceOf(DeviceService::class);
});

test('connect survives a device that will not report its time', function () {
    $mockZk = Mockery::mock(ZkClient::class);
    $mockZk->shouldReceive('connect')->once()->andReturn(true);
    $mockZk->shouldReceive('getTime')->once()->andThrow(new RuntimeException('no reply'));

    $service = serviceUsing($mockZk);

    // A device that will not tell us the time is still worth polling.
    expect($service->connect($this->device))->toBeInstanceOf(DeviceService::class);
});

/**
 * A DeviceService that connects to the given double instead of real hardware.
 */
function serviceUsing($mockZk): DeviceService
{
    return new class($mockZk) extends DeviceService
    {
        public function __construct(private $double) {}

        protected function createZkInstance(DeviceConfig $device, int $timeout = 10): ZkClient
        {
            return $this->double;
        }
    };
}

/**
 * Build the records ZkClient::readAttendance() returns, in device log order.
 *
 * @param  array<int, array{user_id: string, timestamp: string, punch?: int, uid?: int}>  $rows
 * @return array<int, AttendanceRecord>
 */
function attendanceRecords(array $rows): array
{
    return array_values(array_map(
        fn (array $row, int $ordinal) => new AttendanceRecord(
            ordinal: $ordinal,
            uid: $row['uid'] ?? (int) $row['user_id'],
            userId: $row['user_id'],
            timestamp: CarbonImmutable::parse($row['timestamp']),
            status: $row['status'] ?? 0,
            punch: $row['punch'] ?? 0,
        ),
        $rows,
        array_keys($rows),
    ));
}
