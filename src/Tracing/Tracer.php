<?php

namespace Miniargus\Tracing;

/**
 * Sends spans to the local miniargus agent's UDP listener. There is no
 * PHP-wide equivalent of ma-go's tracing.Middleware -- PHP has no single
 * http.Handler chain every framework shares, and no persistent process to
 * wrap once (a request is typically one script execution, start to
 * finish). Instead: construct one Tracer as early as possible in the
 * request (a bootstrap file, an auto_prepend_file script, or your
 * framework's first middleware/event hook), call startRequestSpan() once,
 * and finish() it at the very end (a shutdown function is the reliable
 * place -- see this package's README for a full auto_prepend_file
 * example).
 */
class Tracer
{
    const DEFAULT_AGENT_ADDR = '127.0.0.1:8126';

    /**
     * Bounds the captured query_string tag -- matches miniargus's existing
     * precedent for "a reasonable captured-string snippet length" (see the
     * agent's Postgres query_samples check, defaultQuerySampleMaxLength = 2048).
     */
    const QUERY_STRING_MAX_LENGTH = 2048;

    /**
     * Matches query parameter names that commonly carry secrets --
     * passwords, tokens, API keys, session ids, auth headers passed as
     * params, signed URLs. Mirrors the spirit of Datadog APM's
     * DD_TRACE_OBFUSCATION_QUERY_STRING_REGEXP default: redact by key
     * name, not a denylist of exact values, since the set of secrets an
     * app might pass is unbounded but the *shape* of the parameter name
     * that carries one is fairly predictable.
     */
    const SENSITIVE_QUERY_KEY_PATTERN = '/pass|pwd|secret|token|key|auth|session|credential|signature/i';

    /** @var string */
    private $service;
    /** @var string */
    private $agentAddr;
    /** @var bool */
    private $captureQueryString;
    /** @var resource|null */
    private $socket;
    /** @var bool */
    private $socketAttempted = false;
    /** @var Span[] */
    private $stack = array();

    /**
     * @param string $service            tags every span from this process;
     *                                   every application should set its
     *                                   own name -- it's what distinguishes
     *                                   one app from another in miniargus's
     *                                   traces table.
     * @param string $agentAddr          "host:port" of the agent's UDP
     *                                   trace listener; default matches
     *                                   the agent's own default.
     * @param bool   $captureQueryString captures the request's raw query
     *                                   string as the root span's
     *                                   "query_string" tag -- off by
     *                                   default, since query strings
     *                                   routinely carry session tokens,
     *                                   emails, or other PII that path was
     *                                   deliberately kept free of.
     *                                   Suspicious-looking values are
     *                                   redacted before the span ever
     *                                   leaves this process -- see
     *                                   redactQueryString(). This
     *                                   client-side redaction is
     *                                   best-effort minimization, not the
     *                                   safety boundary: miniargus's own
     *                                   ingestion API re-applies the same
     *                                   redaction server-side regardless
     *                                   of what this SDK sends.
     */
    public function __construct($service, $agentAddr = self::DEFAULT_AGENT_ADDR, $captureQueryString = false)
    {
        $this->service = $service;
        $this->agentAddr = $agentAddr;
        $this->captureQueryString = $captureQueryString;
    }

    /**
     * Starts the root span for the current request. Call once, as early as
     * possible. Reads the incoming traceparent header (W3C Trace Context)
     * from $_SERVER if present, so a request arriving from an
     * already-traced caller continues that trace rather than starting a
     * new one.
     *
     * @param string|null $method defaults to $_SERVER['REQUEST_METHOD']
     * @param string|null $path   defaults to $_SERVER['REQUEST_URI'], query string stripped
     * @return Span
     */
    public function startRequestSpan($method = null, $path = null)
    {
        if ($method === null) {
            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
        }
        if ($path === null) {
            $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
            $qPos = strpos($uri, '?');
            $path = $qPos === false ? $uri : substr($uri, 0, $qPos);
        }

        $traceparent = isset($_SERVER['HTTP_TRACEPARENT']) ? $_SERVER['HTTP_TRACEPARENT'] : '';
        list($traceId, $parentId) = self::parseTraceparent($traceparent);

        $span = new Span($this, $traceId, IdGenerator::generate(8), $parentId, $this->service, true, '', microtime(true));
        $span->setHttpMethod($method);
        $span->setHttpPath($path);

        if ($this->captureQueryString) {
            $queryString = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
            if ($queryString !== '') {
                $span->setTag('query_string', self::redactQueryString($queryString));
            }
        }

        $this->stack[] = $span;
        return $span;
    }

    /**
     * Replaces the value of every key=value pair whose key looks
     * sensitive with a fixed "<redacted>" placeholder, leaving everything
     * else -- parameter order, encoding, bare flags with no value --
     * untouched, then truncates the result to QUERY_STRING_MAX_LENGTH.
     * Operates on the raw (still percent-encoded) string rather than
     * parse_str/http_build_query, which would lose repeated-key ordering
     * and re-escape everything -- undesirable for a value whose only
     * purpose is human-readable debugging context on a span. Mirrors
     * ma-go's tracing.redactQueryString exactly.
     *
     * @param string $raw
     * @return string
     */
    private static function redactQueryString($raw)
    {
        $pairs = explode('&', $raw);
        foreach ($pairs as $i => $pair) {
            $eqPos = strpos($pair, '=');
            if ($eqPos === false) {
                continue;
            }
            $rawKey = substr($pair, 0, $eqPos);
            $key = urldecode($rawKey);
            if (preg_match(self::SENSITIVE_QUERY_KEY_PATTERN, $key)) {
                $pairs[$i] = $rawKey . '=<redacted>';
            }
        }
        return substr(implode('&', $pairs), 0, self::QUERY_STRING_MAX_LENGTH);
    }

    /**
     * Starts a child span named $name, nested under whatever span is
     * currently active (the request span, or another child). Safe to call
     * with nothing active -- a CLI script, a queue worker, a test -- it
     * just starts a new root of its own instead of erroring.
     *
     * @param string $name
     * @return Span
     */
    public function startSpan($name)
    {
        $parent = $this->current();
        if ($parent !== null) {
            $span = new Span(
                $this,
                $parent->getTraceId(),
                IdGenerator::generate(8),
                $parent->getSpanId(),
                $this->service,
                false,
                $name,
                microtime(true)
            );
        } else {
            $span = new Span(
                $this,
                IdGenerator::generate(16),
                IdGenerator::generate(8),
                '',
                $this->service,
                false,
                $name,
                microtime(true)
            );
        }
        $this->stack[] = $span;
        return $span;
    }

    /**
     * The span new child spans nest under if you call startSpan() without
     * threading a specific parent through yourself.
     *
     * @return Span|null
     */
    public function current()
    {
        $n = count($this->stack);
        return $n > 0 ? $this->stack[$n - 1] : null;
    }

    /**
     * @internal called by Span::finish()
     * @param Span $span
     */
    public function popCurrent(Span $span)
    {
        // Removed from wherever it is in the stack, not assumed to be the
        // top -- a span finished out of strict nesting order (e.g. from a
        // finally block after an inner span already finished) shouldn't
        // corrupt the stack for spans still open above it.
        foreach ($this->stack as $i => $s) {
            if ($s === $span) {
                array_splice($this->stack, $i, 1);
                return;
            }
        }
    }

    /**
     * @internal called by Span::finish()
     * @param array $wire
     */
    public function send(array $wire)
    {
        $socket = $this->socket();
        if ($socket === null) {
            return;
        }
        $payload = json_encode($wire);
        if ($payload === false) {
            return;
        }
        // Fire-and-forget: errors here aren't actionable, and PHP's
        // per-request model has no background goroutine to retry on.
        @fwrite($socket, $payload);
    }

    /** @return resource|null */
    private function socket()
    {
        if ($this->socket !== null || $this->socketAttempted) {
            return $this->socket;
        }
        $this->socketAttempted = true;

        $parts = explode(':', $this->agentAddr);
        $host = $parts[0];
        $port = isset($parts[1]) ? (int) $parts[1] : 8126;

        $errno = 0;
        $errstr = '';
        // UDP "connect" doesn't perform a handshake -- it just resolves
        // the address and readies a socket for fire-and-forget writes, so
        // this is cheap even if the agent isn't up yet.
        $sock = @fsockopen('udp://' . $host, $port, $errno, $errstr, 0.1);
        $this->socket = ($sock === false) ? null : $sock;
        return $this->socket;
    }

    /**
     * Parses a W3C traceparent header ("version-traceid-parentid-flags");
     * if absent or malformed, a fresh trace is started (this request
     * becomes a root span). Mirrors ma-go's tracing.traceContextFrom.
     *
     * @param string $traceparent
     * @return array first element trace ID, second element parent ID
     */
    private static function parseTraceparent($traceparent)
    {
        $parts = explode('-', $traceparent);
        if (count($parts) === 4 && strlen($parts[1]) === 32 && strlen($parts[2]) === 16) {
            return array($parts[1], $parts[2]);
        }
        return array(IdGenerator::generate(16), '');
    }
}
