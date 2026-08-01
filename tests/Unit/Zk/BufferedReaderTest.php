<?php

use App\Services\Zk\BufferedReader;
use App\Services\Zk\ZkReply;
use App\Services\Zk\ZkTransport;
use Mithun\PhpZkteco\Libs\Services\Util;
use Tests\Unit\Zk\ReplayTransport;

/**
 * Replays the CMD_PREPARE_BUFFER / CMD_READ_BUFFER exchange captured from a
 * K40/ID on firmware 6.60. See docs/adr/0001-php-buffered-read.md.
 */
it('reads a complete dataset from a real device exchange', function () {
    $transport = ReplayTransport::fromFixture('attendance-k40.exchange.json');

    $data = (new BufferedReader($transport))->read(Util::CMD_ATT_LOG_RRQ);

    expect($data)->toBe(file_get_contents(__DIR__.'/../../Fixtures/zk/attendance-k40.bin'));
});

it('reassembles payloads split across TCP frame boundaries', function () {
    // The device sends each ~1KB packet partly inside its frame and partly as
    // continuation bytes; a reader that trusted the frame alone would truncate.
    $transport = ReplayTransport::fromFixture('attendance-k40.exchange.json');

    $data = (new BufferedReader($transport))->read(Util::CMD_ATT_LOG_RRQ);

    $declared = unpack('V', substr($data, 0, 4))[1];

    expect(strlen($data) - 4)->toBe($declared);
});

it('releases the device buffer when it is finished', function () {
    $transport = ReplayTransport::fromFixture('attendance-k40.exchange.json');

    (new BufferedReader($transport))->read(Util::CMD_ATT_LOG_RRQ);

    expect($transport->isDrained())->toBeTrue();
});

it('fails loudly when the device refuses the buffered protocol', function () {
    $transport = new class implements ZkTransport
    {
        public function command(int $command, string $payload = ''): ZkReply
        {
            return new ZkReply(code: Util::CMD_ACK_ERROR, data: '');
        }

        public function readPacket(): ZkReply
        {
            throw new RuntimeException('should not be reached');
        }

        public function readRaw(int $bytes): string
        {
            throw new RuntimeException('should not be reached');
        }
    };

    expect(fn () => (new BufferedReader($transport))->read(Util::CMD_ATT_LOG_RRQ))
        ->toThrow(RuntimeException::class, 'refused the buffered read protocol');
});

it('returns nothing when the device stages an empty dataset', function () {
    $transport = new class implements ZkTransport
    {
        public function command(int $command, string $payload = ''): ZkReply
        {
            // Flag byte plus a zero size: the device holds no records.
            return new ZkReply(code: Util::CMD_ACK_OK, data: "\x00".pack('V', 0));
        }

        public function readPacket(): ZkReply
        {
            throw new RuntimeException('should not be reached');
        }

        public function readRaw(int $bytes): string
        {
            throw new RuntimeException('should not be reached');
        }
    };

    expect((new BufferedReader($transport))->read(Util::CMD_ATT_LOG_RRQ))->toBe('');
});
