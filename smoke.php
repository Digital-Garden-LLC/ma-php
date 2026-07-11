<?php
// Standalone, dependency-free smoke check -- exercises real Tracer/Span/
// Client behavior over real UDP/Unix sockets without depending on
// PHPUnit's own PHP-version constraints (PHPUnit 5.7, this repo's test
// runner, isn't guaranteed to run cleanly on every PHP version this SDK
// targets, which is orthogonal to whether *this SDK's* code does).
// Useful to re-run against a specific interpreter, e.g.:
//   docker run --rm -v "$PWD":/app -w /app php:5.6-cli php smoke.php
//   docker run --rm -v "$PWD":/app -w /app php:8.3-cli php smoke.php
require __DIR__ . '/src/Tracing/IdGenerator.php';
require __DIR__ . '/src/Tracing/Span.php';
require __DIR__ . '/src/Tracing/Tracer.php';
require __DIR__ . '/src/Events/Client.php';

use Miniargus\Tracing\Tracer;
use Miniargus\Events\Client;

$errors = array();
function check($label, $cond) {
    global $errors;
    echo ($cond ? "OK   " : "FAIL ") . $label . "\n";
    if (!$cond) {
        $errors[] = $label;
    }
}

// --- Tracer / Span over a real UDP socket ---
$collector = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
$addr = stream_socket_get_name($collector, false);

$tracer = new Tracer('smoke-service', $addr);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/smoke?x=1';
$root = $tracer->startRequestSpan();
$child = $tracer->startSpan('db.query');
$child->setTag('table', 'orders');
$child->finish();
$root->setHttpStatus(200);
$root->finish();

$read = array($collector); $write = null; $except = null;
stream_select($read, $write, $except, 1, 0);
$childRaw = stream_socket_recvfrom($collector, 65536);
$read = array($collector);
stream_select($read, $write, $except, 1, 0);
$rootRaw = stream_socket_recvfrom($collector, 65536);

$childData = json_decode($childRaw, true);
$rootData = json_decode($rootRaw, true);

check('child span name = db.query', $childData['name'] === 'db.query');
check('child trace_id matches root', $childData['trace_id'] === $root->getTraceId());
check('child parent_id matches root span_id', $childData['parent_id'] === $root->getSpanId());
check('child tags.table = orders', $childData['tags']['table'] === 'orders');
check('root method = GET', $rootData['method'] === 'GET');
check('root path = /smoke (query string stripped)', $rootData['path'] === '/smoke');
check('root status = 200', $rootData['status'] === 200);
check('root name empty', $rootData['name'] === '');

fclose($collector);

// --- Events client over a real unix socket ---
$sockPath = sys_get_temp_dir() . '/ma-php-smoke-' . uniqid() . '.sock';
$server = stream_socket_server('unix://' . $sockPath, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
$client = new Client($sockPath);
$client->emit('order.completed', array('plan' => 'pro'), array('order_id' => '1234'));

$read = array($server); $write = null; $except = null;
stream_select($read, $write, $except, 1, 0);
$conn = stream_socket_accept($server, 1);
$eventRaw = fread($conn, 65536);
$eventData = json_decode(trim($eventRaw), true);

check('event name = order.completed', $eventData['name'] === 'order.completed');
check('event tags.plan = pro', $eventData['tags']['plan'] === 'pro');
check('event payload.order_id = 1234', $eventData['payload']['order_id'] === '1234');

fclose($conn);
fclose($server);
unlink($sockPath);

echo "\n";
if (count($errors) > 0) {
    echo count($errors) . " FAILED\n";
    exit(1);
}
echo "ALL OK (PHP " . PHP_VERSION . ")\n";
