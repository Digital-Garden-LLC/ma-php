<?php

namespace Miniargus\Tracing;

/**
 * One node in a request's trace tree. Created by Tracer::startRequestSpan
 * (the root, one per incoming request) or Tracer::startSpan (a child, for
 * a nested operation -- a DB query, a downstream HTTP call). Call finish()
 * when the operation completes, typically wrapped in try/finally since
 * PHP has no defer:
 *
 *   $span = $tracer->startSpan('db.query');
 *   try {
 *       $rows = $db->query('...');
 *   } catch (\Exception $e) {
 *       $span->setError($e);
 *       throw $e;
 *   } finally {
 *       $span->finish();
 *   }
 */
class Span
{
    /** @var Tracer */
    private $tracer;
    /** @var bool */
    private $isHttp;
    /** @var string */
    private $traceId;
    /** @var string */
    private $spanId;
    /** @var string */
    private $parentId;
    /** @var string */
    private $service;
    /** @var string */
    private $name;
    /** @var string */
    private $method = '';
    /** @var string */
    private $path = '';
    /** @var int */
    private $status = 0;
    /** @var float */
    private $start;
    /** @var array<string,string> */
    private $tags = array();
    /** @var bool */
    private $finished = false;

    /**
     * Internal -- use Tracer::startRequestSpan() or Tracer::startSpan()
     * instead of constructing a Span directly.
     *
     * @param Tracer $tracer
     * @param string $traceId
     * @param string $spanId
     * @param string $parentId
     * @param string $service
     * @param bool   $isHttp
     * @param string $name
     * @param float  $start microtime(true)
     */
    public function __construct(Tracer $tracer, $traceId, $spanId, $parentId, $service, $isHttp, $name, $start)
    {
        $this->tracer = $tracer;
        $this->traceId = $traceId;
        $this->spanId = $spanId;
        $this->parentId = $parentId;
        $this->service = $service;
        $this->isHttp = $isHttp;
        $this->name = $name;
        $this->start = $start;
    }

    /** @return string */
    public function getTraceId()
    {
        return $this->traceId;
    }

    /** @return string */
    public function getSpanId()
    {
        return $this->spanId;
    }

    /** @internal set by Tracer::startRequestSpan */
    public function setHttpMethod($method)
    {
        $this->method = (string) $method;
        return $this;
    }

    /** @internal set by Tracer::startRequestSpan */
    public function setHttpPath($path)
    {
        $this->path = (string) $path;
        return $this;
    }

    /**
     * Sets the HTTP response status. Call right before finish() -- PHP
     * only knows the final status once the response is about to be sent,
     * e.g. via http_response_code() in a shutdown function.
     *
     * @param int $status
     * @return $this
     */
    public function setHttpStatus($status)
    {
        $this->status = (int) $status;
        return $this;
    }

    /**
     * Attaches a key/value tag. Call before finish() -- tags set
     * afterward are silently dropped, since the span has already been
     * sent.
     *
     * @param string $key
     * @param string $value
     * @return $this
     */
    public function setTag($key, $value)
    {
        if ($this->finished) {
            return $this;
        }
        $this->tags[$key] = (string) $value;
        return $this;
    }

    /**
     * Marks the span failed and records the error's message as a tag -- a
     * convenience for the single most common thing a span needs to report
     * beyond its duration. A no-op if $error is null, so it's safe to call
     * unconditionally.
     *
     * @param \Exception|string|null $error
     * @return $this
     */
    public function setError($error)
    {
        if ($error === null) {
            return $this;
        }
        $message = (is_object($error) && method_exists($error, 'getMessage'))
            ? $error->getMessage()
            : (string) $error;

        $this->setTag('error', 'true');
        $this->setTag('error.message', $message);
        return $this;
    }

    /**
     * Computes the span's duration and sends it to the agent. Idempotent
     * -- only the first call actually sends, so it's safe to call from a
     * finally block even alongside an explicit call on an error path.
     */
    public function finish()
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;

        $durationMs = (microtime(true) - $this->start) * 1000;

        $wire = array(
            'ts'          => self::formatTimestamp($this->start),
            'trace_id'    => $this->traceId,
            'span_id'     => $this->spanId,
            'parent_id'   => $this->parentId,
            'service'     => $this->service,
            'method'      => $this->isHttp ? $this->method : '',
            'path'        => $this->isHttp ? $this->path : '',
            'status'      => $this->isHttp ? $this->status : 0,
            'name'        => $this->isHttp ? '' : $this->name,
            'duration_ms' => $durationMs,
        );
        // Omitted, not sent as an empty object/array, when there are no
        // tags: json_encode(array()) produces `[]`, not `{}`, which fails
        // to unmarshal into Go's map[string]string on the ingestion side
        // (mirrors Go's `json:"tags,omitempty"` on TraceRow.Tags).
        if (!empty($this->tags)) {
            $wire['tags'] = $this->tags;
        }

        $this->tracer->send($wire);
        $this->tracer->popCurrent($this);
    }

    /**
     * RFC3339 with millisecond precision, UTC -- e.g. "2026-07-11T19:20:00.123Z".
     * Matches what Go's encoding/json produces for a time.Time (millisecond
     * precision is also all ClickHouse's DateTime64(3) column stores, so
     * this isn't a real precision loss vs. ma-go's nanosecond timestamps).
     *
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
