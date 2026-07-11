<?php

namespace Miniargus\Tests\Tracing;

use Miniargus\Tracing\IdGenerator;

class IdGeneratorTest extends \PHPUnit_Framework_TestCase
{
    public function testLengthAndUniqueness()
    {
        $a = IdGenerator::generate(16);
        $b = IdGenerator::generate(16);

        $this->assertSame(32, strlen($a)); // 16 bytes -> 32 hex chars
        $this->assertNotSame($a, $b);
        $this->assertRegExp('/^[0-9a-f]+$/', $a);
    }

    public function testShorterLength()
    {
        $id = IdGenerator::generate(8);
        $this->assertSame(16, strlen($id)); // 8 bytes -> 16 hex chars
    }
}
