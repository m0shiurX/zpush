<?php

namespace App\Services\Zk;

use Mithun\PhpZkteco\Libs\Services\Util;
use Mithun\PhpZkteco\Libs\ZKTeco;
use RuntimeException;

/**
 * Talks to a real device over the socket an already-connected ZKTeco holds.
 *
 * Framing lives here rather than in the vendored Util helpers because those
 * share a single `_tcp_buffer` with every other command on the connection, and
 * a bulk read needs to own its own stream position while it is in progress.
 */
final class SocketTransport implements ZkTransport
{
    private string $buffer = '';

    private int $replyId = 0;

    public function __construct(private readonly ZKTeco $zk)
    {
        // Continue the connection's reply sequence rather than restarting it —
        // the device tracks reply ids and ignores commands that rewind them.
        if (strlen($this->zk->_data_recv) >= 8) {
            $this->replyId = unpack('v', substr($this->zk->_data_recv, 6, 2))[1];
        }
    }

    public function command(int $command, string $payload = ''): ZkReply
    {
        $packet = Util::createHeader(
            $command,
            0,
            $this->zk->_session_id,
            $this->replyId,
            $payload,
        );

        if ($this->isTcp()) {
            $sent = @socket_send($this->zk->_zkclient, Util::createTcpPacket($packet), strlen($packet) + Util::TCP_HEADER_SIZE, 0);
        } else {
            $sent = @socket_sendto($this->zk->_zkclient, $packet, strlen($packet), 0, $this->zk->_ip, $this->zk->_port);
        }

        if ($sent === false) {
            throw new RuntimeException('Failed to send command '.$command.' to the device.');
        }

        return $this->readReply(tracksSequence: true);
    }

    public function readPacket(): ZkReply
    {
        // Packets the device pushes carry reply ids of their own, but they are
        // not answers to a command. Letting them advance the sequence leaves the
        // connection unable to issue any further command.
        return $this->readReply(tracksSequence: false);
    }

    public function readRaw(int $bytes): string
    {
        while (strlen($this->buffer) < $bytes) {
            $chunk = $this->receive();

            if ($chunk === '') {
                break;
            }

            $this->buffer .= $chunk;
        }

        $data = substr($this->buffer, 0, $bytes);
        $this->buffer = substr($this->buffer, $bytes);

        return $data;
    }

    /**
     * Read one complete device reply and decode its 8-byte ZK header.
     */
    private function readReply(bool $tracksSequence): ZkReply
    {
        $frameLength = 0;

        if ($this->isTcp()) {
            [$packet, $frameLength] = $this->readTcpFrame();
        } else {
            $packet = $this->receive();
        }

        if (strlen($packet) < 8) {
            throw new RuntimeException('Device sent a reply shorter than a ZK header.');
        }

        $header = unpack('vcommand/vchecksum/vsession/vreply', substr($packet, 0, 8));

        if ($tracksSequence) {
            $this->replyId = $header['reply'];

            // Keep the vendored client's view of the connection current, so
            // commands issued through it after a bulk read still derive a valid
            // reply id and resume from the stream position reached here.
            $this->zk->_data_recv = $packet;
            $this->zk->_tcp_buffer = $this->buffer;
        }

        return new ZkReply(
            code: $header['command'],
            data: substr($packet, 8),
            frameLength: $frameLength,
            replyId: $header['reply'],
        );
    }

    /**
     * Pull one TCP frame off the wire, buffering whatever arrives with it.
     *
     * @return array{0: string, 1: int} the ZK packet and the length the frame claimed
     */
    private function readTcpFrame(): array
    {
        while (strlen($this->buffer) < Util::TCP_HEADER_SIZE) {
            $chunk = $this->receive();

            if ($chunk === '') {
                throw new RuntimeException('Device closed the connection while a reply was expected.');
            }

            $this->buffer .= $chunk;
        }

        if (substr($this->buffer, 0, 4) !== Util::TCP_HEADER) {
            throw new RuntimeException('Device sent a frame without the expected TCP header.');
        }

        $frameLength = unpack('V', substr($this->buffer, 4, 4))[1];
        $this->buffer = substr($this->buffer, Util::TCP_HEADER_SIZE);

        // Take what has already arrived. The caller drains the rest through
        // readRaw() once it knows how much of the payload it actually wants.
        $available = min($frameLength, strlen($this->buffer));
        $packet = substr($this->buffer, 0, $available);
        $this->buffer = substr($this->buffer, $available);

        return [$packet, $frameLength];
    }

    /**
     * One read from the socket.
     */
    private function receive(): string
    {
        $data = '';

        if ($this->isTcp()) {
            $ret = @socket_recv($this->zk->_zkclient, $data, 16384, 0);
        } else {
            $ret = @socket_recvfrom($this->zk->_zkclient, $data, 16384, 0, $ip, $port);
        }

        if ($ret === false || $ret === 0) {
            return '';
        }

        return (string) $data;
    }

    private function isTcp(): bool
    {
        return $this->zk->_protocol === 'tcp';
    }
}
