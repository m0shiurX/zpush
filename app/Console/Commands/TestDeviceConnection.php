<?php

namespace App\Console\Commands;

use App\Models\DeviceConfig;
use Illuminate\Console\Command;
use Mithun\PhpZkteco\Libs\Services\Util;
use Mithun\PhpZkteco\Libs\ZKTeco;

class TestDeviceConnection extends Command
{
    protected $signature = 'devices:test
        {--device= : Device ID from device_configs}
        {--ip= : Direct IP to test}
        {--port=4370 : Port to test}
        {--protocol=tcp : Protocol to use (tcp or udp)}
        {--timeout=5 : Receive timeout in seconds}
        {--debug : Print raw protocol diagnostics}';

    protected $description = 'Test ZKTeco device handshake quickly with diagnostics';

    public function handle(): int
    {
        if (! function_exists('socket_create')) {
            $this->error('PHP sockets extension is not available.');

            return self::FAILURE;
        }

        $resolved = $this->resolveTarget();

        if ($resolved === null) {
            return self::FAILURE;
        }

        ['name' => $name, 'ip' => $ip, 'port' => $port, 'protocol' => $protocol] = $resolved;
        $timeout = max(1, (int) $this->option('timeout'));

        $this->info("Testing device [{$name}] at {$ip}:{$port} via {$protocol} (timeout {$timeout}s)...");

        $start = microtime(true);

        try {
            $zk = $this->createZkInstance($ip, $port, $protocol, $timeout);

            $connected = $zk->connect();
            $elapsed = round(microtime(true) - $start, 2);

            if (! $connected) {
                $ackCode = $this->extractAckCode($zk->_data_recv);
                $this->error("Handshake failed after {$elapsed}s.");

                if ($ackCode !== null) {
                    $label = $this->ackLabel($ackCode);
                    $this->line("Device reply code: {$ackCode} ({$label})");
                } else {
                    $this->line('No valid protocol response received before timeout.');
                }

                if ((bool) $this->option('debug')) {
                    $this->outputDebugDetails($zk);
                }

                $this->newLine();
                $this->warn('Check these on the device:');
                $this->line(' - ADMS/Cloud mode is disabled');
                $this->line(' - Comm password is 0');
                $this->line(' - Port is 4370 (or use --port to test alternatives)');
                $this->line(' - Device firmware supports this SDK protocol mode');

                return self::FAILURE;
            }

            $serial = (string) ($zk->serialNumber() ?: 'N/A');
            $deviceName = (string) ($zk->deviceName() ?: 'N/A');
            $firmware = (string) ($zk->version() ?: 'N/A');
            $zk->disconnect();

            $this->info("Connected successfully in {$elapsed}s.");
            $this->line("Serial: {$serial}");
            $this->line("Device Name: {$deviceName}");
            $this->line("Firmware: {$firmware}");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $elapsed = round(microtime(true) - $start, 2);
            $this->error("Connection error after {$elapsed}s: {$exception->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * @return array{name: string, ip: string, port: int, protocol: string}|null
     */
    private function resolveTarget(): ?array
    {
        $deviceId = $this->option('device');
        $ip = $this->option('ip');

        if ($deviceId === null && $ip === null) {
            $this->error('Provide either --device=ID or --ip=ADDRESS.');

            return null;
        }

        if ($deviceId !== null) {
            $device = DeviceConfig::query()->find($deviceId);

            if ($device === null) {
                $this->error("Device with ID {$deviceId} was not found in device_configs.");

                return null;
            }

            return [
                'name' => $device->name,
                'ip' => $device->ip_address,
                'port' => (int) $device->port,
                'protocol' => $device->protocol ?? 'tcp',
            ];
        }

        if (! is_string($ip) || trim($ip) === '') {
            $this->error('The --ip option must be a non-empty address.');

            return null;
        }

        return [
            'name' => 'Direct IP',
            'ip' => trim($ip),
            'port' => (int) $this->option('port'),
            'protocol' => (string) $this->option('protocol'),
        ];
    }

    private function extractAckCode(string $reply): ?int
    {
        if (strlen($reply) < 8) {
            return null;
        }

        $header = unpack('H2h1/H2h2', substr($reply, 0, 8));

        if (! is_array($header) || ! isset($header['h1'], $header['h2'])) {
            return null;
        }

        return hexdec($header['h2'] . $header['h1']);
    }

    private function ackLabel(int $code): string
    {
        return match ($code) {
            Util::CMD_ACK_OK => 'ACK_OK',
            Util::CMD_ACK_ERROR => 'ACK_ERROR',
            Util::CMD_ACK_DATA => 'ACK_DATA',
            Util::CMD_ACK_UNAUTH => 'ACK_UNAUTH',
            Util::CMD_PREPARE_DATA => 'PREPARE_DATA',
            default => 'UNKNOWN',
        };
    }

    private function outputDebugDetails(ZKTeco $zk): void
    {
        $rawReply = $zk->_data_recv;
        $rawLength = strlen($rawReply);
        $previewLength = min(64, $rawLength);
        $hexPreview = $previewLength > 0
            ? strtoupper(bin2hex(substr($rawReply, 0, $previewLength)))
            : '(empty)';

        $socketErrorCode = socket_last_error($zk->_zkclient);
        $socketErrorText = $socketErrorCode > 0 ? socket_strerror($socketErrorCode) : 'none';

        $this->newLine();
        $this->warn('Debug details:');
        $this->line(" - Raw reply length: {$rawLength} bytes");
        $this->line(" - Raw reply hex (first {$previewLength} bytes): {$hexPreview}");
        $this->line(" - Socket last error: {$socketErrorCode} ({$socketErrorText})");
    }

    /**
     * Create the appropriate ZKTeco instance based on protocol.
     */
    private function createZkInstance(string $ip, int $port, string $protocol, int $timeout): ZKTeco
    {
        return new ZKTeco(
            host: $ip,
            port: $port,
            timeout: $timeout,
            protocol: $protocol,
        );
    }
}
