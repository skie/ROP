<?php
declare(strict_types=1);

namespace Test;

use Exception;
use PHPUnit\Framework\TestCase;
use ROP\Pipe;
use ROP\Railway;

class PipeTest extends TestCase
{
    public function testFlow(): void
    {
        $pipeline = Pipe::flow(
            fn ($x) => $x + 1,
            fn ($x) => $x * 2
        );

        $this->assertEquals(6, $pipeline(2));
    }

    public function testCompose(): void
    {
        $pipeline = Pipe::compose(
            fn ($x) => $x * 2,
            fn ($x) => $x + 1
        );

        $this->assertEquals(6, $pipeline(2));
    }

    public function testChainablePipe(): void
    {
        $result = Pipe::from(2)
            ->pipe(fn ($x) => $x + 1)
            ->pipe(fn ($x) => $x * 2)
            ->value();

        $this->assertEquals(6, $result);
    }

    public function testNestedPipes(): void
    {
        $addOne = fn ($x) => $x + 1;
        $multiplyByTwo = fn ($x) => $x * 2;

        $result = Pipe::from(2)
            ->pipe(Pipe::flow($addOne, $multiplyByTwo))
            ->value();

        $this->assertEquals(6, $result);
    }

    public function testLift(): void
    {
        $divide = function ($x) {
            if ($x === 0) {
                throw new Exception('Division by zero');
            }

            return 10 / $x;
        };

        $safeDivide = Pipe::lift($divide);

        $result1 = $safeDivide(2);
        $this->assertTrue($result1 instanceof Railway);
        $this->assertEquals(5, $result1->getValue());

        $result2 = $safeDivide(0);
        $this->assertTrue($result2 instanceof Railway);
        $this->assertFalse($result2->isSuccess());
    }

    public function testLiftWithChaining(): void
    {
        $double = Pipe::lift(fn ($x) => $x * 2);
        $addOne = Pipe::lift(fn ($x) => $x + 1);

        $result = Railway::of(2)
            ->bind($double)
            ->bind($addOne);

        $this->assertEquals(5, $result->getValue());
    }
}
