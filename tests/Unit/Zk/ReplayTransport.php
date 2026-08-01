<?php

namespace Tests\Unit\Zk;

use App\Services\Zk\ZkReply;
use App\Services\Zk\ZkTransport;
use RuntimeException;

/**
 * Replays a transport exchange captured off a real device.
 *
 * Beyond returning the recorded bytes it asserts the caller asks for them in
 * the recorded order — a bulk read that stops reading pushed packets, or starts
 * requesting them instead, is the exact defect this guards against.
 *
 * @phpstan-type Entry array{op: string, command?: int, payload?: string, code?: int, data?: string, frameLength?: int, bytes?: int}
 */
final class ReplayTransport implements ZkTransport
{
    private int $position = 0;

    /**
     * @param  array<int, array<string, mixed>>  $exchange
     */
    public function __construct(private readonly array $exchange) {}

    public static function fromFixture(string $name): self
    {
        return new self(json_decode(
            file_get_contents(__DIR__.'/../../Fixtures/zk/'.$name),
            associative: true,
        ));
    }

    public function command(int $command, string $payload = ''): ZkReply
    {
        $entry = $this->next('command');

        if ($entry['command'] !== $command) {
            throw new RuntimeException(
                "Expected command {$entry['command']} at step {$this->position}, got {$command}."
            );
        }

        if ($entry['payload'] !== bin2hex($payload)) {
            throw new RuntimeException(
                "Command {$command} at step {$this->position} was sent a different payload than recorded."
            );
        }

        return $this->reply($entry);
    }

    public function readPacket(): ZkReply
    {
        return $this->reply($this->next('readPacket'));
    }

    public function readRaw(int $bytes): string
    {
        $entry = $this->next('readRaw');

        if ($entry['bytes'] !== $bytes) {
            throw new RuntimeException(
                "Expected a raw read of {$entry['bytes']} bytes at step {$this->position}, got {$bytes}."
            );
        }

        return hex2bin($entry['data']);
    }

    /**
     * Did the caller consume the whole recorded exchange?
     */
    public function isDrained(): bool
    {
        return $this->position === count($this->exchange);
    }

    /**
     * @return array<string, mixed>
     */
    private function next(string $op): array
    {
        if (! isset($this->exchange[$this->position])) {
            throw new RuntimeException("Transport ran past the end of the recorded exchange on {$op}.");
        }

        $entry = $this->exchange[$this->position];

        if ($entry['op'] !== $op) {
            throw new RuntimeException(
                "Expected {$entry['op']} at step {$this->position}, got {$op}."
            );
        }

        $this->position++;

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function reply(array $entry): ZkReply
    {
        return new ZkReply(
            code: $entry['code'],
            data: hex2bin($entry['data']),
            frameLength: $entry['frameLength'],
        );
    }
}
