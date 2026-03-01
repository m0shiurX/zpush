<?php

namespace App\Exceptions;

use App\Models\DeviceConfig;
use RuntimeException;

class DeviceConnectionException extends RuntimeException
{
    public function __construct(
        public readonly DeviceConfig $device,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $message = $message ?: "Failed to connect to device [{$device->name}] at {$device->ip_address}:{$device->port}";

        parent::__construct($message, $code, $previous);
    }
}
