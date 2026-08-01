<?php

namespace App\Services\Zk;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Turns a raw attendance dataset into records.
 *
 * Pure by design: it takes bytes and returns records, so the decode can be
 * pinned to payloads captured off real hardware without a device present.
 *
 * It throws rather than returning an empty list when a payload does not make
 * sense. The defect this replaces failed the other way — it parsed the wrong
 * dataset, rejected every malformed record, and handed back `[]` as though the
 * device simply had nothing to report.
 */
final class AttendanceParser
{
    /**
     * Record layout this parser understands: uid(2), user id(24), status(1),
     * timestamp(4), punch(1), reserved(8).
     */
    private const RECORD_SIZE = 40;

    /**
     * @param  string  $payload  the dataset as {@see BufferedReader} returned it
     * @param  int  $recordCount  how many records the device reports holding;
     *                            0 to infer the layout from the payload alone
     * @return array<int, AttendanceRecord> in device log order
     *
     * @throws RuntimeException when the payload is not a coherent dataset
     */
    public function parse(string $payload, int $recordCount): array
    {
        if ($payload === '') {
            return [];
        }

        if (strlen($payload) < 4) {
            throw new RuntimeException('Attendance payload is too short to carry a size header.');
        }

        $declaredSize = unpack('V', substr($payload, 0, 4))[1];
        $body = substr($payload, 4);

        if (strlen($body) !== $declaredSize) {
            throw new RuntimeException(
                "Attendance payload declared {$declaredSize} bytes but carries ".strlen($body).'.'
            );
        }

        if ($declaredSize === 0) {
            return [];
        }

        $recordSize = $this->recordSize($declaredSize, $recordCount);

        $records = [];
        $ordinal = 0;

        for ($offset = 0; $offset + $recordSize <= $declaredSize; $offset += $recordSize) {
            $records[] = $this->decodeRecord(substr($body, $offset, $recordSize), $ordinal++);
        }

        return $records;
    }

    /**
     * Work out how wide each record is, preferring the device's own count.
     */
    private function recordSize(int $declaredSize, int $recordCount): int
    {
        if ($recordCount > 0) {
            if ($declaredSize % $recordCount !== 0) {
                throw new RuntimeException(
                    "Attendance payload of {$declaredSize} bytes does not divide into {$recordCount} records."
                );
            }

            $size = intdiv($declaredSize, $recordCount);
        } else {
            $size = self::RECORD_SIZE;
        }

        if ($size !== self::RECORD_SIZE) {
            throw new RuntimeException(
                "Unsupported attendance record size of {$size} bytes; this parser reads ".self::RECORD_SIZE.'-byte records.'
            );
        }

        if ($declaredSize % self::RECORD_SIZE !== 0) {
            throw new RuntimeException(
                "Attendance payload of {$declaredSize} bytes is not a whole number of records."
            );
        }

        return $size;
    }

    private function decodeRecord(string $raw, int $ordinal): AttendanceRecord
    {
        $fields = unpack('vuid/a24userId/Cstatus/Vtimestamp/Cpunch', $raw);

        $userId = explode("\x00", $fields['userId'])[0];

        return new AttendanceRecord(
            ordinal: $ordinal,
            uid: $fields['uid'],
            userId: $userId,
            timestamp: $this->decodeTimestamp($fields['timestamp']),
            status: $fields['status'],
            punch: $fields['punch'],
        );
    }

    /**
     * ZKTeco packs a timestamp as seconds-since-2000 in a base-31/base-12
     * calendar, so it cannot be handed to a normal date constructor directly.
     */
    private function decodeTimestamp(int $encoded): CarbonImmutable
    {
        $second = $encoded % 60;
        $encoded = intdiv($encoded, 60);

        $minute = $encoded % 60;
        $encoded = intdiv($encoded, 60);

        $hour = $encoded % 24;
        $encoded = intdiv($encoded, 24);

        $day = $encoded % 31 + 1;
        $encoded = intdiv($encoded, 31);

        $month = $encoded % 12 + 1;
        $year = intdiv($encoded, 12) + 2000;

        return CarbonImmutable::create($year, $month, $day, $hour, $minute, $second);
    }
}
