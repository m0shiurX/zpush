<?php

namespace App\Services\Zk;

use Mithun\PhpZkteco\Libs\Services\Util;

/**
 * One decoded reply from a ZKTeco device.
 *
 * A ZK packet is an 8-byte header (command, checksum, session, reply id)
 * followed by the payload. Over TCP that packet is itself wrapped in an
 * 8-byte frame carrying the total length, which is what {@see $frameLength}
 * reports — the device may split a payload across the frame boundary, so
 * callers need it to know whether more raw bytes are still on the wire.
 */
final readonly class ZkReply
{
    public function __construct(
        public int $code,
        public string $data,
        public int $frameLength = 0,
        public int $replyId = 0,
    ) {}

    /**
     * Did the device accept the command?
     */
    public function ok(): bool
    {
        return in_array($this->code, [
            Util::CMD_ACK_OK,
            Util::CMD_PREPARE_DATA,
            Util::CMD_DATA,
        ], true);
    }

    /**
     * How many payload bytes the TCP frame promised but did not deliver.
     */
    public function outstandingBytes(): int
    {
        if ($this->frameLength <= 0) {
            return 0;
        }

        return max(0, ($this->frameLength - 8) - strlen($this->data));
    }
}
