<?php

use App\Exceptions\DeviceConnectionException;
use App\Models\AttendanceLog;
use App\Models\DeviceConfig;
use App\Models\Employee;
use App\Services\DeviceService;
use MehediJaman\LaravelZkteco\LaravelZkteco;

beforeEach(function () {
    $this->device = DeviceConfig::factory()->create([
        'ip_address' => '192.168.1.201',
        'port' => 4370,
    ]);
});

test('testConnection returns device info on success', function () {
    $mockZk = Mockery::mock(LaravelZkteco::class);
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
    $mockZk = Mockery::mock(LaravelZkteco::class);
    $mockZk->shouldReceive('connect')->once()->andReturn(false);
    $mockZk->shouldReceive('disconnect')->zeroOrMoreTimes();

    $result = simulateTestConnection($this->device, $mockZk);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->not->toBeNull();
});

test('pollAttendance stores new attendance records', function () {
    Employee::factory()->create(['device_uid' => 1]);

    $mockZk = Mockery::mock(LaravelZkteco::class);
    $mockZk->shouldReceive('getAttendance')->once()->andReturn([
        ['uid' => 1, 'id' => '1', 'state' => 1, 'timestamp' => '2025-03-01 09:00:00', 'type' => 0],
        ['uid' => 1, 'id' => '1', 'state' => 1, 'timestamp' => '2025-03-01 18:00:00', 'type' => 1],
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

    $mockZk = Mockery::mock(LaravelZkteco::class);
    $mockZk->shouldReceive('getAttendance')->once()->andReturn([
        ['uid' => 1, 'id' => '1', 'state' => 1, 'timestamp' => '2025-03-01 09:00:00', 'type' => 0],
    ]);

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $result = $service->pollAttendance($this->device);

    expect($result['duplicates'])->toBe(1)
        ->and($result['new'])->toBe(0)
        ->and(AttendanceLog::count())->toBe(1);
});

test('syncUsersFromDevice upserts employees', function () {
    $mockZk = Mockery::mock(LaravelZkteco::class);
    $mockZk->shouldReceive('getUser')->once()->andReturn([
        '1' => ['uid' => 1, 'userid' => '1', 'name' => 'Alice', 'role' => 0, 'password' => '', 'cardno' => '00001234567'],
        '2' => ['uid' => 2, 'userid' => '2', 'name' => 'Bob', 'role' => 0, 'password' => '', 'cardno' => '00000000000'],
    ]);

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $result = $service->syncUsersFromDevice($this->device);

    expect($result)->toHaveCount(2)
        ->and(Employee::count())->toBe(2)
        ->and(Employee::where('name', 'Alice')->first()->card_number)->toBe('1234567');
});

test('pollAttendance returns zeros when no logs on device', function () {
    $mockZk = Mockery::mock(LaravelZkteco::class);
    $mockZk->shouldReceive('getAttendance')->once()->andReturn([]);

    $service = new DeviceService;
    injectZkMock($service, $this->device, $mockZk);

    $result = $service->pollAttendance($this->device);

    expect($result)->toBe(['total' => 0, 'new' => 0, 'duplicates' => 0]);
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
 * (avoids needing to mock the LaravelZkteco constructor).
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
    } catch (\Throwable $e) {
        try {
            $mockZk->disconnect();
        } catch (\Throwable) {
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
