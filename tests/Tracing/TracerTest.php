<?php

namespace Miniargus\Tests\Tracing;

use Miniargus\Tracing\Tracer;

class TracerTest extends \PHPUnit_Framework_TestCase
{
    /** @var resource */
    private $collector;
    /** @var string */
    private $collectorAddr;

    protected function setUp()
    {
        $errno = 0;
        $errstr = '';
        $this->collector = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        if ($this->collector === false) {
            $this->fail('failed to bind UDP collector: ' . $errstr);
        }
        $this->collectorAddr = stream_socket_get_name($this->collector, false);
        unset($_SERVER['HTTP_TRACEPARENT']);
    }

    protected function tearDown()
    {
        if ($this->collector) {
            fclose($this->collector);
        }
        unset($_SERVER['HTTP_TRACEPARENT']);
    }

    /**
     * @param float $timeoutSeconds
     * @return array|null decoded JSON, or null if nothing arrived in time
     */
    private function recv($timeoutSeconds = 1.0)
    {
        $read = array($this->collector);
        $write = null;
        $except = null;
        $sec = (int) $timeoutSeconds;
        $usec = (int) (($timeoutSeconds - $sec) * 1000000);
        $n = stream_select($read, $write, $except, $sec, $usec);
        if (!$n) {
            return null;
        }
        $raw = stream_socket_recvfrom($this->collector, 65536);
        return json_decode($raw, true);
    }

    public function testStartRequestSpan_NoTraceparentStartsRoot()
    {
        $tracer = new Tracer('checkout-api', $this->collectorAddr);

        $span = $tracer->startRequestSpan('GET', '/cart');
        $span->setHttpStatus(200);
        $span->finish();

        $got = $this->recv();
        $this->assertNotNull($got, 'expected a span over UDP');
        $this->assertSame('checkout-api', $got['service']);
        $this->assertSame('GET', $got['method']);
        $this->assertSame('/cart', $got['path']);
        $this->assertSame(200, $got['status']);
        $this->assertSame('', $got['parent_id']);
        $this->assertSame(32, strlen($got['trace_id']));
    }

    public function testStartRequestSpan_StripsQueryStringFromDefaultPath()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/search?q=shoes&page=2';
        $tracer = new Tracer('checkout-api', $this->collectorAddr);

        $span = $tracer->startRequestSpan();
        $span->finish();

        $got = $this->recv();
        $this->assertSame('/search', $got['path']);

        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    public function testStartRequestSpan_WithQueryStringCapture_CapturesRawQuery()
    {
        $_SERVER['QUERY_STRING'] = 'page=2&sort=price';
        $tracer = new Tracer('checkout-api', $this->collectorAddr, true);

        $span = $tracer->startRequestSpan('GET', '/search');
        $span->finish();

        $got = $this->recv();
        $this->assertSame('page=2&sort=price', $got['tags']['query_string']);

        unset($_SERVER['QUERY_STRING']);
    }

    public function testStartRequestSpan_DefaultDoesNotCaptureQueryString()
    {
        $_SERVER['QUERY_STRING'] = 'page=2';
        $tracer = new Tracer('checkout-api', $this->collectorAddr);

        $span = $tracer->startRequestSpan('GET', '/search');
        $span->finish();

        $got = $this->recv();
        $this->assertArrayNotHasKey('tags', $got);

        unset($_SERVER['QUERY_STRING']);
    }

    public function testStartRequestSpan_WithQueryStringCapture_NoQueryStringPresent_TagAbsent()
    {
        unset($_SERVER['QUERY_STRING']);
        $tracer = new Tracer('checkout-api', $this->collectorAddr, true);

        $span = $tracer->startRequestSpan('GET', '/search');
        $span->finish();

        $got = $this->recv();
        $this->assertArrayNotHasKey('tags', $got);
    }

    public function testStartRequestSpan_WithQueryStringCapture_RedactsSensitiveValues()
    {
        $_SERVER['QUERY_STRING'] = 'page=2&token=abc123';
        $tracer = new Tracer('checkout-api', $this->collectorAddr, true);

        $span = $tracer->startRequestSpan('GET', '/search');
        $span->finish();

        $got = $this->recv();
        $this->assertSame('page=2&token=<redacted>', $got['tags']['query_string']);

        unset($_SERVER['QUERY_STRING']);
    }

    public function testStartRequestSpan_WithQueryStringCapture_TruncatesAtMaxLength()
    {
        $_SERVER['QUERY_STRING'] = str_repeat('a=1&', 1000);
        $tracer = new Tracer('checkout-api', $this->collectorAddr, true);

        $span = $tracer->startRequestSpan('GET', '/search');
        $span->finish();

        $got = $this->recv();
        $this->assertSame(2048, strlen($got['tags']['query_string']));

        unset($_SERVER['QUERY_STRING']);
    }

    public function testStartRequestSpan_HonorsIncomingTraceparent()
    {
        $_SERVER['HTTP_TRACEPARENT'] = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        $tracer = new Tracer('checkout-api', $this->collectorAddr);

        $span = $tracer->startRequestSpan('GET', '/cart');
        $span->finish();

        $got = $this->recv();
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $got['trace_id']);
        $this->assertSame('00f067aa0ba902b7', $got['parent_id']);
    }

    public function testStartRequestSpan_MalformedTraceparentStartsFreshRoot()
    {
        $_SERVER['HTTP_TRACEPARENT'] = 'not-a-traceparent';
        $tracer = new Tracer('checkout-api', $this->collectorAddr);

        $span = $tracer->startRequestSpan('GET', '/cart');
        $span->finish();

        $got = $this->recv();
        $this->assertSame('', $got['parent_id']);
        $this->assertSame(32, strlen($got['trace_id']));
    }

    public function testStartSpan_ChildNestsUnderRequestSpan()
    {
        $tracer = new Tracer('checkout-api', $this->collectorAddr);

        $root = $tracer->startRequestSpan('GET', '/checkout');
        $child = $tracer->startSpan('db.query');
        $child->setTag('table', 'orders');
        $child->finish();
        $root->setHttpStatus(200);
        $root->finish();

        // child finishes first, so it's sent first
        $first = $this->recv();
        $second = $this->recv();

        $this->assertSame('db.query', $first['name']);
        $this->assertSame('', $first['method']);
        $this->assertSame($root->getTraceId(), $first['trace_id']);
        $this->assertSame($root->getSpanId(), $first['parent_id']);
        $this->assertSame('orders', $first['tags']['table']);

        $this->assertSame('GET', $second['method']);
        $this->assertSame('', $second['name']);
        $this->assertSame('', $second['parent_id']);
        $this->assertSame($root->getTraceId(), $second['trace_id']);
    }

    public function testStartSpan_ChildOfChildNestsCorrectly()
    {
        $tracer = new Tracer('worker', $this->collectorAddr);

        $root = $tracer->startRequestSpan('GET', '/checkout');
        $mid = $tracer->startSpan('http.client');
        $leaf = $tracer->startSpan('db.query');
        $leaf->finish();
        $mid->finish();
        $root->finish();

        $leafGot = $this->recv();
        $this->assertSame($root->getTraceId(), $leafGot['trace_id']);
        $this->assertSame($mid->getSpanId(), $leafGot['parent_id']);
    }

    public function testStartSpan_NoActiveSpanStartsNewRoot()
    {
        $tracer = new Tracer('worker', $this->collectorAddr);

        $span = $tracer->startSpan('standalone-job');
        $span->finish();

        $got = $this->recv();
        $this->assertSame('standalone-job', $got['name']);
        $this->assertSame('', $got['parent_id']);
    }

    public function testFinish_IsIdempotent()
    {
        $tracer = new Tracer('worker', $this->collectorAddr);
        $span = $tracer->startSpan('op');
        $span->finish();
        $span->finish();
        $span->finish();

        $this->assertNotNull($this->recv(), 'expected the one legitimate send');
        $this->assertNull($this->recv(0.2), 'finish() must not send more than once');
    }

    public function testSetError_AddsErrorTags()
    {
        $tracer = new Tracer('worker', $this->collectorAddr);
        $span = $tracer->startSpan('op');
        $span->setError(new \Exception('boom'));
        $span->finish();

        $got = $this->recv();
        $this->assertSame('true', $got['tags']['error']);
        $this->assertSame('boom', $got['tags']['error.message']);
    }

    public function testSetError_NullIsNoop()
    {
        $tracer = new Tracer('worker', $this->collectorAddr);
        $span = $tracer->startSpan('op');
        $span->setError(null);
        $span->finish();

        $got = $this->recv();
        $this->assertArrayNotHasKey('tags', $got);
    }

    public function testSetTag_AfterFinishIsDroppedNotError()
    {
        $tracer = new Tracer('worker', $this->collectorAddr);
        $span = $tracer->startSpan('op');
        $span->finish();
        $span->setTag('late', 'value'); // must not throw

        $got = $this->recv();
        $this->assertArrayNotHasKey('tags', $got);
    }

    public function testEmptyTags_OmittedFromWirePayloadNotSentAsJsonArray()
    {
        // json_encode(array()) produces [] not {}, which would fail to
        // unmarshal into Go's map[string]string on the ingestion side --
        // guards against that regressing.
        $tracer = new Tracer('worker', $this->collectorAddr);
        $span = $tracer->startSpan('op');
        $span->finish();

        $read = array($this->collector);
        $write = null;
        $except = null;
        $this->assertTrue((bool) stream_select($read, $write, $except, 1, 0));
        $raw = stream_socket_recvfrom($this->collector, 65536);

        $this->assertNotContains('"tags":[]', $raw);
    }

    public function testUnreachableAgent_FinishDoesNotThrow()
    {
        $tracer = new Tracer('worker', '127.0.0.1:1'); // nothing listens on port 1
        $span = $tracer->startSpan('op');
        $span->finish(); // must not throw
        $this->assertTrue(true);
    }
}
