<?php
declare(strict_types=1);

namespace Test;

use PHPUnit\Framework\TestCase;
use ROP\Result;

class ResultTest extends TestCase
{
    public function testSuccessCreation(): void
    {
        $result = Result::success(42);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(42, $result->getValue());
        $this->assertNull($result->getError());
    }

    public function testFailureCreation(): void
    {
        $result = Result::failure('error');

        $this->assertFalse($result->isSuccess());
        $this->assertNull($result->getValue());
        $this->assertEquals('error', $result->getError());
    }
}
