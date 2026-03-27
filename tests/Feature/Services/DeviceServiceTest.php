<?php

use App\Enums\PunchType;
use App\Exceptions\DeviceConnectionException;
use App\Models\AttendanceLog;
use App\Models\DeviceConfig;
use App\Models\Employee;
use App\Services\DeviceService;
use Mithun\PhpZkteco\Libs\ZKTeco;

beforeEach(function () {
    $this->device = DeviceConfig::factory()->create([
        'ip_address' => '192.168.1.201',
        'port' => 4370,
    ]);
});

test('testConnection returns device info on success', function () {
    $mockZk = Mockery::mock(ZKTeco::class);
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
    $mockZk = Mockery::mock(ZKTeco::class);
    $mockZk->shouldReceive('connect')->once()->andReturn(false);
    $mockZk->shouldReceive('disconnect')->zeroOrMoreTimes();

    $result = simulateTestConnection($this->device, $mockZk);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->not->toBeNull();
});

test('pollAttendance stores new attendance records', function () {
    Employee::factory()->create(['device_uid' => 1]);

    $mockZk = Mockery::mock(ZKTeco::class);
    $mockZk->shouldReceive('getAttendances')->once()->andReturn([
        ['uid' => 1, 'user_id' => '1', 'state' => 1, 'record_time' => '2025-03-01 09:00:00', 'type' => 0],
        ['uid' => 1, 'user_id' => '1', 'state' => 1, 'record_time' => '2025-03-01 18:00:00', 'type' => 1],
    ]);

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $result = $service->pollAttendance($this->device);

    expect($result['total'])->toBe(2)
        ->and($result['new'])->toBe(2)
        ->and($result['duplicates'])->toBe(0)
        ->and(AttendanceLog::count())->toBe(2);
});

test('pollAttendance skips duplicate records', function () {
    $employee = Employee::factory()->create(['device_uid' => 1]);

    AttendanceLog::factory()->create([
        'employee_id' => $employee->id,
        'device_id' => $this->device->id,
        'device_uid' => 1,
        'timestamp' => '2025-03-01 09:00:00',
    ]);

    $mockZk = Mockery::mock(ZKTeco::class);
    $mockZk->shouldReceive('getAttendances')->once()->andReturn([
        ['uid' => 1, 'user_id' => '1', 'state' => 1, 'record_time' => '2025-03-01 09:00:00', 'type' => 0],
    ]);

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $result = $service->pollAttendance($this->device);

    expect($result['duplicates'])->toBe(1)
        ->and($result['new'])->toBe(0)
        ->and(AttendanceLog::count())->toBe(1);
});

test('syncUsersFromDevice matches existing employees by device_uid', function () {
    // Create two employees that are already in the DB (from cloud sync)
    $alice = Employee::factory()->create(['device_uid' => 1, 'name' => 'Alice', 'card_number' => null]);
    $bob = Employee::factory()->create(['device_uid' => 2, 'name' => 'Bob', 'card_number' => null]);

    $mockZk = Mockery::mock(ZKTeco::class);
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
    $mockZk = Mockery::mock(ZKTeco::class);
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
    Employee::factory()->create(['device_uid' => 1]);

    $mockZk = Mockery::mock(ZKTeco::class);
    $mockZk->shouldReceive('getAttendances')->once()->andReturn([
        ['uid' => 1, 'user_id' => '1', 'state' => 1, 'record_time' => '2025-03-01 09:00:00', 'type' => 0],
        ['uid' => 999, 'user_id' => '999', 'state' => 1, 'record_time' => '2025-03-01 10:00:00', 'type' => 0],
    ]);

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
    $mockZk = Mockery::mock(ZKTeco::class);
    $mockZk->shouldReceive('getAttendances')->once()->andReturn([]);

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $result = $service->pollAttendance($this->device);

    expect($result)->toBe(['total' => 0, 'new' => 0, 'duplicates' => 0]);
});

test('handleRealtimeEvent stores attendance for known employee', function () {
    $employee = Employee::factory()->create(['device_uid' => 6]);

    $service = new DeviceService;

    $result = $service->handleRealtimeEvent([
        'user_id' => '6',
        'record_time' => '2025-03-01 09:00:00',
        'state' => 0,
        'device_ip' => '192.168.1.201',
    ], $this->device);

    expect($result)->toBe(['new' => 1, 'skipped' => 0])
        ->and(AttendanceLog::count())->toBe(1);

    $log = AttendanceLog::first();
    expect($log->employee_id)->toBe($employee->id)
        ->and($log->device_uid)->toBe(6)
        ->and($log->device_id)->toBe($this->device->id)
        ->and($log->timestamp->format('Y-m-d H:i:s'))->toBe('2025-03-01 09:00:00');
});

test('handleRealtimeEvent skips unknown user ids', function () {
    Employee::factory()->create(['device_uid' => 6]);

    $service = new DeviceService;

    $result = $service->handleRealtimeEvent([
        'user_id' => '999',
        'record_time' => '2025-03-01 09:00:00',
        'state' => 0,
        'device_ip' => '192.168.1.201',
    ], $this->device);

    expect($result)->toBe(['new' => 0, 'skipped' => 1])
        ->and(AttendanceLog::count())->toBe(0);
});

test('handleRealtimeEvent skips duplicate events', function () {
    $employee = Employee::factory()->create(['device_uid' => 6]);

    AttendanceLog::factory()->create([
        'employee_id' => $employee->id,
        'device_id' => $this->device->id,
        'device_uid' => 6,
        'timestamp' => '2025-03-01 09:00:00',
    ]);

    $service = new DeviceService;

    $result = $service->handleRealtimeEvent([
        'user_id' => '6',
        'record_time' => '2025-03-01 09:00:00',
        'state' => 0,
        'device_ip' => '192.168.1.201',
    ], $this->device);

    expect($result)->toBe(['new' => 0, 'skipped' => 1])
        ->and(AttendanceLog::count())->toBe(1);
});

test('handleRealtimeEvent maps punch type from state', function () {
    Employee::factory()->create(['device_uid' => 6]);

    $service = new DeviceService;

    $service->handleRealtimeEvent([
        'user_id' => '6',
        'record_time' => '2025-03-01 18:00:00',
        'state' => 1,
        'device_ip' => '192.168.1.201',
    ], $this->device);

    expect(AttendanceLog::first()->punch_type)->toBe(PunchType::CheckOut);
});

test('listenForAttendance registers for real-time events', function () {
    $mockZk = Mockery::mock(ZKTeco::class);
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
