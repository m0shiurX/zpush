<?php

use App\Services\Zk\AttendanceParser;

/**
 * These run against bytes captured off a real K40/ID (firmware 6.60) on
 * 2026-08-01, with the expected output produced by pyzk's own decode algorithm
 * applied to those same bytes. See docs/adr/0001-php-buffered-read.md.
 */
function fixtureBytes(): string
{
    return file_get_contents(__DIR__.'/../../Fixtures/zk/attendance-k40.bin');
}

/**
 * @return array{record_count: int, records: array<int, array<string, mixed>>}
 */
function fixtureExpected(): array
{
    return json_decode(
        file_get_contents(__DIR__.'/../../Fixtures/zk/attendance-k40.expected.json'),
        associative: true,
    );
}

it('decodes every record in a real device payload', function () {
    $expected = fixtureExpected();

    $records = (new AttendanceParser)->parse(fixtureBytes(), $expected['record_count']);

    expect($records)->toHaveCount($expected['record_count']);
});

it('decodes each record identically to the reference implementation', function () {
    $expected = fixtureExpected();

    $records = (new AttendanceParser)->parse(fixtureBytes(), $expected['record_count']);

    foreach ($expected['records'] as $index => $want) {
        expect($records[$index]->uid)->toBe($want['uid'], "uid at index {$index}")
            ->and($records[$index]->userId)->toBe($want['user_id'], "user_id at index {$index}")
            ->and($records[$index]->status)->toBe($want['status'], "status at index {$index}")
            ->and($records[$index]->punch)->toBe($want['punch'], "punch at index {$index}")
            ->and($records[$index]->timestamp->format('Y-m-d H:i:s'))->toBe($want['timestamp'], "timestamp at index {$index}");
    }
});

it('numbers records by their position in the device log', function () {
    $records = (new AttendanceParser)->parse(fixtureBytes(), fixtureExpected()['record_count']);

    expect($records[0]->ordinal)->toBe(0)
        ->and($records[1]->ordinal)->toBe(1)
        ->and(end($records)->ordinal)->toBe(count($records) - 1);
});

it('preserves device log order rather than sorting by time', function () {
    $records = (new AttendanceParser)->parse(fixtureBytes(), fixtureExpected()['record_count']);

    // The captured log contains a clock-reset episode: year-2000 stamps appear
    // after 2026 ones. Re-ordering here would let a timestamp watermark skip
    // every punch made after a reset.
    $timestamps = array_map(fn ($r) => $r->timestamp->timestamp, $records);
    $sorted = $timestamps;
    sort($sorted);

    expect($timestamps)->not->toBe($sorted);
});

it('infers the record size when the device does not report a count', function () {
    $records = (new AttendanceParser)->parse(fixtureBytes(), 0);

    expect($records)->toHaveCount(fixtureExpected()['record_count']);
});

it('returns nothing for an empty payload', function () {
    expect((new AttendanceParser)->parse('', 0))->toBe([]);
});

it('refuses a payload whose declared size does not match its body', function () {
    $truncated = substr(fixtureBytes(), 0, 500);

    expect(fn () => (new AttendanceParser)->parse($truncated, 60))
        ->toThrow(RuntimeException::class);
});

it('rejects a payload it cannot divide into whole records', function () {
    // 100 bytes of body, claimed as 7 records: 100/7 is not a record size.
    $payload = pack('V', 100).str_repeat("\x00", 100);

    expect(fn () => (new AttendanceParser)->parse($payload, 7))
        ->toThrow(RuntimeException::class);
});
