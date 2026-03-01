<?php

namespace App\Enums;

enum SyncDirection: string
{
    case CloudUp = 'cloud_up';
    case CloudDown = 'cloud_down';
    case DeviceUp = 'device_up';
    case DeviceDown = 'device_down';

    /**
     * Get the display label for the sync direction.
     */
    public function label(): string
    {
        return match ($this) {
            self::CloudUp => 'Local → Cloud',
            self::CloudDown => 'Cloud → Local',
            self::DeviceUp => 'Local → Device',
            self::DeviceDown => 'Device → Local',
        };
    }
}
