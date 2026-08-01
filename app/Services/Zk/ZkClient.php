<?php

namespace App\Services\Zk;

use Mithun\PhpZkteco\Libs\Services\Util;
use Mithun\PhpZkteco\Libs\ZKTeco;

/**
 * The device client this application talks to.
 *
 * Extends the vendored ZKTeco client rather than replacing it: connection,
 * users, fingerprint enrolment, time and real-time capture all work as shipped.
 * What does not work is bulk reading — see
 * docs/adr/0001-php-buffered-read.md — so that one path is served here instead.
 *
 * Living in app/ rather than as a vendor patch means `composer update` cannot
 * quietly reintroduce the defect.
 */
class ZkClient extends ZKTeco
{
    private ?AttendanceParser $parser = null;

    /**
     * Read the device's attendance log.
     *
     * Prefer this over the inherited getAttendances(), which asks for the log
     * with a bare CMD_ATT_LOG_RRQ. On K40 firmware 6.60 the device answers that
     * with its user table, the records fail validation, and the caller receives
     * an empty array indistinguishable from a device with nothing to report.
     *
     * @return array<int, AttendanceRecord> in device log order, oldest first
     */
    public function readAttendance(): array
    {
        $payload = $this->bufferedReader()->read(Util::CMD_ATT_LOG_RRQ);

        return $this->parser()->parse($payload, 0);
    }

    private function bufferedReader(): BufferedReader
    {
        // A fresh transport per read: it picks up the connection's current reply
        // id, which the vendored client advances on every command it issues.
        return new BufferedReader(new SocketTransport($this));
    }

    private function parser(): AttendanceParser
    {
        return $this->parser ??= new AttendanceParser;
    }
}
