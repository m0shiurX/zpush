<?php

namespace App\Services;

use MehediJaman\LaravelZkteco\LaravelZkteco;
use MehediJaman\LaravelZkteco\Lib\Util;

/**
 * TCP transport adapter for ZKTeco devices.
 *
 * The upstream LaravelZkteco package hardcodes UDP sockets. Most modern
 * ZKTeco devices (K40, iFace, etc.) communicate over TCP on port 4370.
 *
 * The ZKTeco TCP protocol wraps every ZK payload in an 8-byte header:
 *   bytes 0-3: magic  \x50\x50\x82\x7d
 *   bytes 4-7: little-endian uint32 payload length
 *
 * This class extends LaravelZkteco and overrides the socket layer to
 * use SOCK_STREAM with proper TCP framing, while reusing all the
 * existing Util helpers for packet construction and validation.
 */
class ZktecoTcp extends LaravelZkteco
{
    private const TCP_MAGIC = "\x50\x50\x82\x7d";

    private bool $tcpConnected = false;

    /**
     * @param  string  $ip  Device IP address
     * @param  int  $port  Device port (default 4370)
     * @param  int  $timeout  Socket timeout in seconds
     */
    public function __construct(string $ip, int $port = 4370, int $timeout = 60)
    {
        // Intentionally skip parent — it creates a UDP socket.
        $this->_ip = $ip;
        $this->_port = $port;

        $this->_zkclient = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $timeoutArr = ['sec' => $timeout, 'usec' => 500000];
        socket_set_option($this->_zkclient, SOL_SOCKET, SO_RCVTIMEO, $timeoutArr);
        socket_set_option($this->_zkclient, SOL_SOCKET, SO_SNDTIMEO, $timeoutArr);
    }

    /**
     * Establish the TCP connection and perform the ZK handshake.
     */
    public function connect(): bool
    {
        $this->_section = __METHOD__;

        if (! $this->tcpConnected) {
            $result = @socket_connect($this->_zkclient, $this->_ip, $this->_port);

            if (! $result) {
                return false;
            }

            $this->tcpConnected = true;
        }

        $buf = Util::createHeader(Util::CMD_CONNECT, 0, 0, -1 + Util::USHRT_MAX, '');

        if (! $this->tcpSend($buf)) {
            return false;
        }

        $reply = $this->tcpRecv();

        if ($reply === false || strlen($reply) === 0) {
            return false;
        }

        $this->_data_recv = $reply;

        $u = unpack('H2h1/H2h2/H2h3/H2h4/H2h5/H2h6', substr($reply, 0, 8));
        $session = hexdec($u['h6'] . $u['h5']);

        if (empty($session)) {
            return false;
        }

        $this->_session_id = $session;

        return Util::checkValid($reply);
    }

    /**
     * Send the disconnect command and close the TCP socket.
     */
    public function disconnect(): bool
    {
        $this->_section = __METHOD__;

        if (! $this->tcpConnected || strlen($this->_data_recv) < 8) {
            $this->closeSocket();

            return true;
        }

        $u = unpack('H2h1/H2h2/H2h3/H2h4/H2h5/H2h6/H2h7/H2h8', substr($this->_data_recv, 0, 8));
        $reply_id = hexdec($u['h8'] . $u['h7']);

        $buf = Util::createHeader(Util::CMD_EXIT, 0, $this->_session_id, $reply_id, '');

        $this->tcpSend($buf);

        $reply = $this->tcpRecv();

        if ($reply !== false) {
            $this->_data_recv = $reply;
        }

        $this->_session_id = 0;
        $this->closeSocket();

        return $reply !== false ? Util::checkValid($reply) : true;
    }

    /**
     * Send a command and receive the response over TCP.
     *
     * @param  string  $command
     * @param  string  $command_string
     * @param  string  $type
     * @return bool|mixed
     */
    public function _command($command, $command_string, $type = Util::COMMAND_TYPE_GENERAL)
    {
        $chksum = 0;
        $session_id = $this->_session_id;

        $u = unpack('H2h1/H2h2/H2h3/H2h4/H2h5/H2h6/H2h7/H2h8', substr($this->_data_recv, 0, 8));
        $reply_id = hexdec($u['h8'] . $u['h7']);

        $buf = Util::createHeader($command, $chksum, $session_id, $reply_id, $command_string);

        if (! $this->tcpSend($buf)) {
            return false;
        }

        try {
            $reply = $this->tcpRecv();

            if ($reply === false) {
                return false;
            }

            $this->_data_recv = $reply;

            $u = unpack('H2h1/H2h2/H2h3/H2h4/H2h5/H2h6', substr($reply, 0, 8));

            $ret = false;
            $session = hexdec($u['h6'] . $u['h5']);

            if ($type === Util::COMMAND_TYPE_GENERAL && $session_id === $session) {
                $ret = substr($reply, 8);
            } elseif ($type === Util::COMMAND_TYPE_DATA && ! empty($session)) {
                $ret = $session;
            }

            return $ret;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Override getUser to use TCP-aware bulk data reading.
     *
     * @return array [userid => [uid, userid, name, cardno, role, password]]
     */
    public function getUser()
    {
        $this->_section = __METHOD__;

        $command = Util::CMD_USER_TEMP_RRQ;
        $command_string = chr(Util::FCT_USER);

        $session = $this->_command($command, $command_string, Util::COMMAND_TYPE_DATA);

        if ($session === false) {
            return [];
        }

        $userData = $this->tcpRecvBulkData();

        $users = [];

        if (! empty($userData)) {
            $userData = substr($userData, 11);

            while (strlen($userData) > 72) {
                $u = unpack('H144', substr($userData, 0, 72));

                $u1 = hexdec(substr($u[1], 2, 2));
                $u2 = hexdec(substr($u[1], 4, 2));
                $uid = $u1 + ($u2 * 256);
                $cardno = hexdec(substr($u[1], 78, 2) . substr($u[1], 76, 2) . substr($u[1], 74, 2) . substr($u[1], 72, 2)) . ' ';
                $role = hexdec(substr($u[1], 6, 2)) . ' ';
                $password = hex2bin(substr($u[1], 8, 16)) . ' ';
                $name = hex2bin(substr($u[1], 24, 74)) . ' ';
                $userid = hex2bin(substr($u[1], 98, 72)) . ' ';

                $password = explode(chr(0), $password, 2);
                $password = $password[0];
                $userid = explode(chr(0), $userid, 2);
                $userid = $userid[0];
                $name = explode(chr(0), $name, 3);
                $name = mb_convert_encoding($name[0], 'UTF-8', 'UTF-8');
                $cardno = str_pad($cardno, 11, '0', STR_PAD_LEFT);

                if ($name == '') {
                    $name = $userid;
                }

                $users[$userid] = [
                    'uid' => $uid,
                    'userid' => $userid,
                    'name' => $name,
                    'role' => intval($role),
                    'password' => $password,
                    'cardno' => $cardno,
                ];

                $userData = substr($userData, 72);
            }
        }

        return $users;
    }

    /**
     * Override getAttendance to use TCP-aware bulk data reading.
     *
     * @return array [uid, id, state, timestamp, type]
     */
    public function getAttendance()
    {
        $this->_section = __METHOD__;

        $command = Util::CMD_ATT_LOG_RRQ;
        $command_string = '';

        $session = $this->_command($command, $command_string, Util::COMMAND_TYPE_DATA);

        if ($session === false) {
            return [];
        }

        $attData = $this->tcpRecvBulkData();

        $attendance = [];

        if (! empty($attData)) {
            $attData = substr($attData, 10);

            while (strlen($attData) > 40) {
                $u = unpack('H78', substr($attData, 0, 39));

                $u1 = hexdec(substr($u[1], 4, 2));
                $u2 = hexdec(substr($u[1], 6, 2));
                $uid = $u1 + ($u2 * 256);
                $id = hex2bin(substr($u[1], 8, 18));
                $id = str_replace(chr(0), '', $id);
                $state = hexdec(substr($u[1], 56, 2));
                $timestamp = Util::decodeTime(hexdec(Util::reverseHex(substr($u[1], 58, 8))));
                $type = hexdec(Util::reverseHex(substr($u[1], 66, 2)));

                $attendance[] = [
                    'uid' => $uid,
                    'id' => $id,
                    'state' => $state,
                    'timestamp' => $timestamp,
                    'type' => $type,
                ];

                $attData = substr($attData, 40);
            }
        }

        return $attendance;
    }

    // ==========================================
    // TCP Transport Layer
    // ==========================================

    /**
     * Send a ZK payload wrapped in the TCP header.
     */
    private function tcpSend(string $payload): bool
    {
        $tcpHeader = self::TCP_MAGIC . pack('V', strlen($payload));
        $packet = $tcpHeader . $payload;

        $sent = @socket_send($this->_zkclient, $packet, strlen($packet), 0);

        return $sent !== false;
    }

    /**
     * Receive a single TCP-framed ZK response.
     *
     * Reads the 8-byte TCP header, validates the magic bytes, then reads
     * exactly the number of payload bytes declared in the header.
     */
    private function tcpRecv(int $maxPayload = 65536): string|false
    {
        $header = $this->tcpReadExact(8);

        if ($header === false || strlen($header) < 8) {
            return false;
        }

        if (substr($header, 0, 4) !== self::TCP_MAGIC) {
            return false;
        }

        $payloadLen = unpack('V', substr($header, 4, 4))[1];

        if ($payloadLen <= 0) {
            return '';
        }

        $payloadLen = min($payloadLen, $maxPayload);

        return $this->tcpReadExact($payloadLen);
    }

    /**
     * Receive bulk data after a CMD_PREPARE_DATA response.
     *
     * Mirrors the logic of Util::recData() but uses TCP framing.
     * Each data chunk arrives as a separate TCP-framed message.
     */
    private function tcpRecvBulkData(int $maxErrors = 10): string
    {
        $bytes = Util::getSize($this);

        if (! $bytes) {
            return '';
        }

        $data = '';
        $received = 0;
        $errors = 0;
        $first = true;

        while ($received < $bytes) {
            $chunk = $this->tcpRecv();

            if ($chunk === false || $chunk === '') {
                if ($errors < $maxErrors) {
                    $errors++;
                    usleep(100_000);

                    continue;
                }

                break;
            }

            if (! $first) {
                $chunk = substr($chunk, 8);
            }

            $data .= $chunk;
            $received += strlen($chunk);
            $first = false;
        }

        // Read the final acknowledgment / CMD_FREE_DATA packet.
        $this->tcpRecv();

        return $data;
    }

    /**
     * Read exactly $length bytes from the TCP socket.
     */
    private function tcpReadExact(int $length): string|false
    {
        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = '';
            $ret = @socket_recv($this->_zkclient, $chunk, $remaining, 0);

            if ($ret === false || $ret === 0) {
                return $remaining === $length ? false : $data;
            }

            $data .= $chunk;
            $remaining -= $ret;
        }

        return $data;
    }

    /**
     * Close the TCP socket and reset state.
     */
    private function closeSocket(): void
    {
        if ($this->_zkclient) {
            @socket_close($this->_zkclient);
        }

        $this->tcpConnected = false;
    }
}
