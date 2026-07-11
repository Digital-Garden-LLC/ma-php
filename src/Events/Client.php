<?php

namespace Miniargus\Events;

/**
 * Delivers custom application events to the local miniargus agent over its
 * Unix socket.
 *
 * Unlike ma-go's events.Client (a persistent background goroutine draining
 * a queue), PHP's per-request process model has nothing to run that queue
 * in -- a request is one script execution, not a long-lived process. Each
 * emit() call instead opens a short-lived connection, writes, and closes,
 * synchronously, with a short timeout. Never throws: a down or slow agent
 * must never break the calling request.
 */
class Client
{
    /** @var string */
    private $socketPath;
    /** @var float */
    private $timeoutSeconds;

    /**
     * @param string $socketPath     e.g. "/tmp/miniargus-agent.sock", must
     *                               match the agent's --socket flag
     * @param float  $timeoutSeconds connect timeout; kept short since a
     *                               slow/unreachable agent must not add
     *                               latency to the caller
     */
    public function __construct($socketPath, $timeoutSeconds = 0.05)
    {
        $this->socketPath = $socketPath;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * @param string $name
     * @param array  $tags    omitted from the wire payload entirely if empty
     * @param mixed  $payload any JSON-encodable value; omitted if null
     */
    public function emit($name, array $tags = array(), $payload = null)
    {
        $event = array(
            'name' => $name,
            'ts'   => self::formatTimestamp(microtime(true)),
        );
        // Omitted, not sent empty, for the same reason Span does this --
        // json_encode(array()) is `[]`, which doesn't unmarshal into Go's
        // map[string]string.
        if (!empty($tags)) {
            $event['tags'] = $tags;
        }
        if ($payload !== null) {
            $event['payload'] = $payload;
        }

        $json = json_encode($event);
        if ($json === false) {
            return;
        }

        $errno = 0;
        $errstr = '';
        $sock = @fsockopen('unix://' . $this->socketPath, -1, $errno, $errstr, $this->timeoutSeconds);
        if ($sock === false) {
            return;
        }
        @fwrite($sock, $json . "\n");
        @fclose($sock);
    }

    /**
     * @param float $microtime
     * @return string
     */
    private static function formatTimestamp($microtime)
    {
        $seconds = (int) $microtime;
        $millis = (int) round(($microtime - $seconds) * 1000);
        if ($millis >= 1000) {
            $millis -= 1000;
            $seconds += 1;
        }
        return gmdate('Y-m-d\TH:i:s', $seconds) . '.' . str_pad($millis, 3, '0', STR_PAD_LEFT) . 'Z';
    }
}
