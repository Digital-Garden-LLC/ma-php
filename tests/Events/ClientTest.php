<?php

namespace Miniargus\Tests\Events;

use Miniargus\Events\Client;

class ClientTest extends \PHPUnit_Framework_TestCase
{
    /** @var string */
    private $socketPath;
    /** @var resource */
    private $server;

    protected function setUp()
    {
        $this->socketPath = sys_get_temp_dir() . '/ma-php-test-' . uniqid() . '.sock';
        $errno = 0;
        $errstr = '';
        $this->server = stream_socket_server(
            'unix://' . $this->socketPath,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );
        if ($this->server === false) {
            $this->fail('failed to bind unix socket: ' . $errstr);
        }
    }

    protected function tearDown()
    {
        if ($this->server) {
            fclose($this->server);
        }
        if (file_exists($this->socketPath)) {
            unlink($this->socketPath);
        }
    }

    /**
     * @param float $timeoutSeconds
     * @return string|null
     */
    private function acceptAndRead($timeoutSeconds = 1.0)
    {
        $read = array($this->server);
        $write = null;
        $except = null;
        $sec = (int) $timeoutSeconds;
        $usec = (int) (($timeoutSeconds - $sec) * 1000000);
        if (!stream_select($read, $write, $except, $sec, $usec)) {
            return null;
        }
        $conn = @stream_socket_accept($this->server, $timeoutSeconds);
        if ($conn === false) {
            return null;
        }
        $data = fread($conn, 65536);
        fclose($conn);
        return $data;
    }

    public function testEmit_DeliversToAgent()
    {
        $client = new Client($this->socketPath);
        $client->emit('order.completed', array('plan' => 'pro'), array('order_id' => '1234'));

        $raw = $this->acceptAndRead();
        $this->assertNotNull($raw, 'expected an event to arrive');

        $got = json_decode(trim($raw), true);
        $this->assertSame('order.completed', $got['name']);
        $this->assertSame('pro', $got['tags']['plan']);
        $this->assertSame('1234', $got['payload']['order_id']);
        $this->assertArrayHasKey('ts', $got);
    }

    public function testEmit_UnreachableSocketDoesNotThrow()
    {
        $client = new Client('/tmp/definitely-not-a-real-agent-' . uniqid() . '.sock');
        $client->emit('order.completed'); // must not throw
        $this->assertTrue(true);
    }

    public function testEmit_OmitsEmptyTagsAndNullPayload()
    {
        $client = new Client($this->socketPath);
        $client->emit('heartbeat');

        $raw = $this->acceptAndRead();
        $this->assertNotNull($raw);

        $got = json_decode(trim($raw), true);
        $this->assertArrayNotHasKey('tags', $got);
        $this->assertArrayNotHasKey('payload', $got);
    }

    public function testEmit_MultipleEventsEachDeliveredIndependently()
    {
        $client = new Client($this->socketPath);

        $client->emit('first');
        $first = $this->acceptAndRead();
        $this->assertNotNull($first);
        $this->assertSame('first', json_decode(trim($first), true)['name']);

        $client->emit('second');
        $second = $this->acceptAndRead();
        $this->assertNotNull($second);
        $this->assertSame('second', json_decode(trim($second), true)['name']);
    }
}
