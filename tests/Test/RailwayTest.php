<?php
declare(strict_types=1);

namespace Test;

use Exception;
use PHPUnit\Framework\TestCase;
use ROP\Railway;

class RailwayTest extends TestCase
{
    public function testSuccessPath(): void
    {
        $railway = Railway::of(42);

        $this->assertTrue($railway->isSuccess());
        $this->assertEquals(42, $railway->getValue());
    }

    public function testFailurePath(): void
    {
        $railway = Railway::fail('error');

        $this->assertFalse($railway->isSuccess());
        $this->assertEquals('error', $railway->getError());
    }

    public function testBind(): void
    {
        $railway = Railway::of(2)
            ->bind(fn ($x) => Railway::of($x * 2));

        $this->assertTrue($railway->isSuccess());
        $this->assertEquals(4, $railway->getValue());
    }

    public function testBindWithFailure(): void
    {
        $railway = Railway::of(2)
            ->bind(fn ($x) => Railway::fail('error'));

        $this->assertFalse($railway->isSuccess());
        $this->assertEquals('error', $railway->getError());
    }

    public function testMap(): void
    {
        $railway = Railway::of(2)
            ->map(fn ($x) => $x * 2);

        $this->assertTrue($railway->isSuccess());
        $this->assertEquals(4, $railway->getValue());
    }

    public function testLift(): void
    {
        $railway = Railway::of(2)
            ->bind(Railway::lift(fn ($x) => $x * 2));

        $this->assertTrue($railway->isSuccess());
        $this->assertEquals(4, $railway->getValue());
    }

    public function testLiftWithFailure(): void
    {
        $railway = Railway::of(2)
            ->bind(Railway::lift(function () {
                throw new Exception('error');
            }));

        $this->assertFalse($railway->isSuccess());
        $this->assertStringContainsString('error', $railway->getError());
    }

    public function testTee(): void
    {
        $sideEffect = 0;
        $railway = Railway::of(2)
            ->tee(function ($x) use (&$sideEffect) {
                $sideEffect = $x;
            });

        $this->assertEquals(2, $sideEffect);
        $this->assertEquals(2, $railway->getValue());
    }

    public function testDoubleMap(): void
    {
        $successRailway = Railway::of(2)
            ->doubleMap(
                fn ($x) => $x * 2,
                fn ($e) => "mapped: $e"
            );

        $failureRailway = Railway::fail('error')
            ->doubleMap(
                fn ($x) => $x * 2,
                fn ($e) => "mapped: $e"
            );

        $this->assertEquals(4, $successRailway->getValue());
        $this->assertEquals('mapped: error', $failureRailway->getError());
    }

    public function testTryCatch(): void
    {
        $success = Railway::of(2)->tryCatch(
            fn ($x) => $x * 2
        );

        $failure = Railway::of(2)->tryCatch(
            function () {
                throw new Exception('error');
            }
        );

        $this->assertEquals(4, $success->getValue());
        $this->assertStringContainsString('error', $failure->getError()->getMessage());
    }

    public function testTryWith(): void
    {
        $railway = Railway::of(2)
            ->bind(Railway::tryWith(fn ($x) => $x * 2));

        $this->assertTrue($railway->isSuccess());
        $this->assertEquals(4, $railway->getValue());
    }

    public function testTryWithFailure(): void
    {
        $railway = Railway::of(2)
            ->bind(Railway::tryWith(function () {
                throw new Exception('error');
            }));

        $this->assertFalse($railway->isSuccess());
        $this->assertStringContainsString('error', $railway->getError()->getMessage());
    }

    public function testPlus(): void
    {
        $r1 = Railway::of(2);
        $r2 = Railway::of(3);

        $combined = Railway::plus(
            fn ($a, $b) => $a + $b,
            fn ($errors) => implode(', ', $errors),
            $r1,
            $r2
        );

        $this->assertEquals(5, $combined->getValue());
    }

    public function testUnite(): void
    {
        $r1 = Railway::of(2);
        $r2 = Railway::of(3);

        $united = $r1->unite($r2);

        $this->assertEquals(3, $united->getValue());
    }

    public function testMatch(): void
    {
        $success = Railway::of(2)
            ->match(
                fn ($x) => $x * 2,
                fn ($e) => 0
            );

        $failure = Railway::fail('error')
            ->match(
                fn ($x) => $x * 2,
                fn ($e) => 0
            );

        $this->assertEquals(4, $success);
        $this->assertEquals(0, $failure);
    }
}
