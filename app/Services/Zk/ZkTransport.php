<?php

namespace App\Services\Zk;

/**
 * The wire underneath a ZKTeco conversation.
 *
 * Exists so {@see BufferedReader} can be driven by recorded device bytes in a
 * test as easily as by a live socket. Implementations own framing, session and
 * reply-id bookkeeping; callers only ever see decoded replies.
 */
interface ZkTransport
{
    /**
     * Send a command and read back its reply.
     */
    public function command(int $command, string $payload = ''): ZkReply;

    /**
     * Read a packet the device sent unprompted.
     *
     * After announcing a dataset the device pushes it without waiting to be
     * asked again, so a bulk read spends most of its time here rather than in
     * {@see command()}.
     */
    public function readPacket(): ZkReply;

    /**
     * Read exactly $bytes of raw payload continuing the current reply.
     */
    public function readRaw(int $bytes): string;
}
