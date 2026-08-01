<?php

namespace App\Services\Zk;

use Carbon\CarbonImmutable;

/**
 * One punch as the device recorded it.
 *
 * A ZKTeco device carries two identifiers per user: {@see $uid}, an internal
 * slot number the device assigns, and {@see $userId}, the ID an operator sees
 * and types. They are not interchangeable — the same person can keep one while
 * the other is edited. Employee matching keys on $userId; $uid exists only for
 * commands that address a device slot.
 */
final readonly class AttendanceRecord
{
    public function __construct(
        public int $ordinal,
        public int $uid,
        public string $userId,
        public CarbonImmutable $timestamp,
        public int $status,
        public int $punch,
    ) {}
}
