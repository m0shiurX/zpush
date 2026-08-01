<?php

namespace App\Services\Zk;

use Mithun\PhpZkteco\Libs\Services\Util;
use RuntimeException;

/**
 * Reads a large dataset off a ZKTeco device using the buffered protocol.
 *
 * The library this project vendors asks for bulk data with a bare command
 * (e.g. CMD_ATT_LOG_RRQ) and reads the reply through CMD_PREPARE_DATA. On K40
 * firmware 6.60 the device answers that request with the wrong dataset — the
 * user table instead of the attendance table — and the caller silently gets
 * nothing back. See docs/adr/0001-php-buffered-read.md.
 *
 * The buffered protocol asks the device to stage the dataset first
 * (CMD_PREPARE_BUFFER), then pulls it down in chunks (CMD_READ_BUFFER). That is
 * the path pyzk takes over TCP, and it returns the correct dataset on the same
 * hardware.
 */
final class BufferedReader
{
    private const CMD_PREPARE_BUFFER = 1503;

    private const CMD_READ_BUFFER = 1504;

    /**
     * Largest chunk the device will serve in one CMD_READ_BUFFER over TCP.
     */
    private const MAX_CHUNK = 0xFFC0;

    public function __construct(private readonly ZkTransport $transport) {}

    /**
     * Stage $command's dataset on the device and download all of it.
     *
     * @param  int  $command  the dataset to stage, e.g. Util::CMD_ATT_LOG_RRQ
     * @param  int  $fct  command-specific selector (0 for whole-table reads)
     * @param  int  $ext  command-specific argument (0 for whole-table reads)
     * @return string the raw dataset, still in device wire format
     *
     * @throws RuntimeException when the device refuses the buffered protocol
     */
    public function read(int $command, int $fct = 0, int $ext = 0): string
    {
        $reply = $this->transport->command(
            self::CMD_PREPARE_BUFFER,
            pack('c', 1).pack('v', $command).pack('V', $fct).pack('V', $ext),
        );

        if (! $reply->ok()) {
            throw new RuntimeException(
                "Device refused the buffered read protocol for command {$command} (replied {$reply->code})."
            );
        }

        // Small datasets come straight back in the reply rather than being staged.
        if ($reply->code === Util::CMD_DATA) {
            return $this->completeReply($reply);
        }

        // Otherwise byte 0 is a flag and bytes 1-4 hold the staged size.
        if (strlen($reply->data) < 5) {
            throw new RuntimeException('Device staged a buffered read but did not report its size.');
        }

        $size = unpack('V', substr($reply->data, 1, 4))[1];

        if ($size <= 0) {
            $this->freeData();

            return '';
        }

        $data = '';
        $start = 0;

        while ($start < $size) {
            $chunk = min(self::MAX_CHUNK, $size - $start);
            $data .= $this->readChunk($start, $chunk);
            $start += $chunk;
        }

        $this->freeData();

        return $data;
    }

    /**
     * Pull one chunk of the staged dataset.
     */
    private function readChunk(int $start, int $size): string
    {
        $lastError = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $reply = $this->transport->command(
                self::CMD_READ_BUFFER,
                pack('V', $start).pack('V', $size),
            );

            if (! $reply->ok()) {
                $lastError = "device replied {$reply->code}";

                continue;
            }

            if ($reply->code === Util::CMD_DATA) {
                return $this->completeReply($reply);
            }

            // CMD_PREPARE_DATA: the chunk arrives as its own stream of packets.
            $streamed = $this->readStreamedChunk($reply, $size);

            if ($streamed !== null) {
                return $streamed;
            }

            $lastError = 'chunk stream ended without an acknowledgement';
        }

        throw new RuntimeException("Could not read chunk at {$start} ({$size} bytes): {$lastError}.");
    }

    /**
     * Read a chunk the device announced with CMD_PREPARE_DATA.
     *
     * The announcement carries only sizes. What follows is pushed by the device
     * as a run of CMD_DATA packets, closed by CMD_ACK_OK — so this reads packets
     * rather than requesting them.
     */
    private function readStreamedChunk(ZkReply $announcement, int $expected): ?string
    {
        // The announcement may carry a leading fragment of the payload itself.
        $collected = $this->completeReply($announcement);
        $collected = strlen($collected) > 8 ? substr($collected, 8) : '';

        $acknowledged = false;

        while (! $acknowledged) {
            $packet = $this->transport->readPacket();

            if ($packet->code === Util::CMD_ACK_OK) {
                $acknowledged = true;

                break;
            }

            if ($packet->code !== Util::CMD_DATA) {
                return null;
            }

            $collected .= $this->completeReply($packet);

            // Stop pulling payload once the chunk is whole, but keep reading:
            // the device still owes a closing acknowledgement, and leaving it
            // on the wire puts every later reply on this connection off by one.
            if (strlen($collected) >= $expected) {
                $closing = $this->transport->readPacket();
                $acknowledged = $closing->code === Util::CMD_ACK_OK;

                break;
            }
        }

        if (! $acknowledged || strlen($collected) < $expected) {
            return null;
        }

        return substr($collected, 0, $expected);
    }

    /**
     * Drain any payload the TCP frame promised but that had not yet arrived.
     */
    private function completeReply(ZkReply $reply): string
    {
        $outstanding = $reply->outstandingBytes();

        if ($outstanding <= 0) {
            return $reply->data;
        }

        return $reply->data.$this->transport->readRaw($outstanding);
    }

    /**
     * Release the device's staging buffer. Best effort — a device that will not
     * free it still gave us the data we came for.
     */
    private function freeData(): void
    {
        try {
            $this->transport->command(Util::CMD_FREE_DATA);
        } catch (\Throwable) {
            // The read succeeded; a stuck buffer is the device's problem to reset.
        }
    }
}
