<?php

namespace pader\swbeanstalk;

use Swoole\Coroutine\Client;

/**
 * Swbeanstalk
 *
 * A beanstalkd client base on swoole coroutine.
 *
 * @package pader\swbeanstalk
 */
class Swbeanstalk
{

    const DEFAULT_PRI = 60;
    const DEFAULT_TTR = 30;

    protected $config;
    protected $connection;
    protected $lastError = null;

    public $debug = false;

    protected $using = 'default';

    protected $watching = ['default' => true];

    /**
     * Swbeanstalk constructor.
     *
     * @param string $host
     * @param int $port
     * @param int $connectTimeout Connect timeout, -1 means never timeout.
     * @param int $timeout Read, write timeout, -1 means never timeout.
     */
    public function __construct($host = '127.0.0.1', $port = 11300, $connectTimeout = 1, $timeout = -1)
    {
        $this->config = compact('host', 'port');

        $this->connection = new Client(SWOOLE_SOCK_TCP);
        $this->connection->set([
            'socket_connect_timeout' => $connectTimeout,
            'socket_timeout' => $timeout
        ]);
    }

    public function connect()
    {
        if ($this->isConnected()) {
            $this->connection->close(true);
        }
        return $this->connection->connect($this->config['host'], $this->config['port']);
    }

    public function isConnected()
    {
        return $this->connection && $this->connection->isConnected();
    }

    public function put($data, $pri = self::DEFAULT_PRI, $delay = 0, $ttr = self::DEFAULT_TTR)
    {
        $this->send(sprintf("put %d %d %d %d\r\n%s", $pri, $delay, $ttr, strlen($data), $data));
        $res = $this->recv();

        if ($res['status'] === 'INSERTED') {
            return $res['meta'][0];
        }

        $this->setError($res['status']);
        return false;
    }

    public function useTube($tube)
    {
        // we should not have to do anything here
        if ($tube === $this->using) {
            return true;
        }

        $this->send(sprintf("use %s", $tube));
        $ret = $this->recv();
        if ($ret['status'] === 'USING' && $ret['meta'][0] === $tube) {

            $this->using = $tube;
            return true;
        }

        $this->setError($ret['status'], "Use tube $tube failed.");
        return false;
    }

    public function reserve($timeout = null)
    {
        if (isset($timeout)) {
            $this->send(sprintf('reserve-with-timeout %d', $timeout));
        } else {
            $this->send('reserve');
        }

        $res = $this->recv();

        if ($res['status'] === 'RESERVED') {
            list($id, $bytes) = $res['meta'];
            return [
                'id' => $id,
                'body' => substr($res['body'], 0, $bytes)
            ];
        }

        $this->setError($res['status']);
        return false;
    }

    public function delete($id)
    {
        return $this->sendv(sprintf('delete %d', $id), 'DELETED');
    }

    public function release($id, $pri = self::DEFAULT_PRI, $delay = 0)
    {
        return $this->sendv(sprintf('release %d %d %d', $id, $pri, $delay), 'RELEASED');
    }

    public function bury($id)
    {
        return $this->sendv(sprintf('bury %d', $id), 'BURIED');
    }

    public function touch($id)
    {
        return $this->sendv(sprintf('touch %d', $id), 'TOUCHED');
    }

    public function watch($tube)
    {
        if (isset($this->watching[$tube])) {
            return true;
        }

        $this->send(sprintf('watch %s', $tube));
        $res = $this->recv();

        if ($res['status'] === 'WATCHING') {
            $this->watching[$tube] = true;
            return $res['meta'][0];
        }

        $this->setError($res['status']);
        return false;
    }

    public function ignore($tube)
    {
        if (isset($this->watching[$tube])) {
            unset($this->watching[$tube]);
            return $this->sendv(sprintf('ignore %s', $tube), 'WATCHING');
        }

        return false;
    }

    public function peek($id)
    {
        $this->send(sprintf('peek %d', $id));
        return $this->peekRead();
    }

    public function peekReady()
    {
        $this->send('peek-ready');
        return $this->peekRead();
    }

    public function peekDelayed()
    {
        $this->send('peek-delayed');
        return $this->peekRead();
    }

    public function peekBuried()
    {
        $this->send('peek-buried');
        return $this->peekRead();
    }

    protected function peekRead()
    {
        $res = $this->recv();

        if ($res['status'] === 'FOUND') {
            list($id, $bytes) = $res['meta'];
            return [
                'id' => $id,
                'body' => substr($res['body'], 0, $bytes)
            ];
        }

        $this->setError($res['status']);
        return false;
    }

    public function kick($bound)
    {
        $this->send(sprintf('kick %d', $bound));
        $res = $this->recv();

        if ($res['status'] === 'KICKED') {
            return $res['meta'][0];
        }

        $this->setError($res['status']);
        return false;
    }

    public function kickJob($id)
    {
        return $this->sendv(sprintf('kick-job %d', $id), 'KICKED');
    }

    public function statsJob($id)
    {
        $this->send(sprintf('stats-job %d', $id));
        return $this->statsRead();
    }

    public function statsTube($tube)
    {
        $this->send(sprintf('stats-tube %s', $tube));
        return $this->statsRead();
    }

    public function stats()
    {
        $this->send('stats');
        return $this->statsRead();
    }

    public function listTubes()
    {
        $this->send('list-tubes');
        return $this->statsRead();
    }

    public function listTubeUsed(bool $askServer = false)
    {
        if ($askServer) {
            $this->send('list-tube-used');
            $res = $this->recv();
            if ($res['status'] === 'USING') {
                $this->using = $res['meta'][0];
            } else {
                $this->setError($res['status']);
                return false;
            }
        }

        return $this->using;
    }

    public function listTubesWatched(bool $askServer = false)
    {
        if ($askServer) {
            $this->send('list-tubes-watched');
            $result = $this->statsRead();

            $this->watching = array_fill_keys($result, true);
        }

        return array_keys($this->watching);
    }

    protected function statsRead()
    {
        $res = $this->recv();

        if ($res['status'] === 'OK') {
            list($bytes) = $res['meta'];
            $body = trim($res['body']);

            $data = array_slice(explode("\n", $body), 1);
            $result = [];

            foreach ($data as $row) {
                if ('-' === $row[0]) {
                    $value = substr($row, 2);
                    $key = null;
                } else {
                    $pos = strpos($row, ':');
                    $key = substr($row, 0, $pos);
                    $value = substr($row, $pos + 2);
                }
                if (is_numeric($value)) {
                    $value = (int)$value === $value ? (int)$value : (float)$value;
                }
                isset($key) ? $result[$key] = $value : array_push($result, $value);
            }
            return $result;
        }

        $this->setError($res['status']);
        return false;
    }

    public function pauseTube($tube, $delay)
    {
        return $this->sendv(sprintf('pause-tube %s %d', $tube, $delay), 'PAUSED');
    }

    protected function sendv($cmd, $status)
    {
        $this->send($cmd);
        $res = $this->recv();

        if ($res['status'] !== $status) {
            $this->setError($res['status']);
            return false;
        }

        return true;
    }

    protected function send($cmd)
    {
        if (!$this->isConnected()) {
            throw new \RuntimeException('No connecting found while writing data to socket.');
        }

        $cmd .= "\r\n";

        if ($this->debug) {
            $this->wrap($cmd, true);
        }

        return $this->connection->send($cmd);
    }

    protected function recv()
    {
        if (!$this->isConnected()) {
            throw new \RuntimeException('No connection found while reading data from socket.');
        }

        $recv = $this->connection->recv();
        $metaEnd = strpos($recv, "\r\n");
        $meta = explode(' ', substr($recv, 0, $metaEnd));
        $status = array_shift($meta);

        if ($this->debug) {
            $this->wrap($recv, false);
        }

        return [
            'status' => $status,
            'meta' => $meta,
            'body' => substr($recv, $metaEnd + 2)
        ];
    }

    public function disconnect()
    {
        if ($this->isConnected()) {
            $this->send('quit');
            $this->connection->close();
        }

        if ($this->connection) {
            $this->connection = null;
        }
    }

    protected function setError($status, $msg = '')
    {
        $this->lastError = compact('status', 'msg');
    }

    public function getError()
    {
        if ($this->lastError) {
            $error = $this->lastError;
            $this->lastError = null;
            return $error;
        }
        return null;
    }

    protected function wrap($output, $out)
    {
        $line = $out ? '----->>' : '<<-----';
        echo "\r\n$line\r\n$output\r\n$line\r\n";
    }

}

