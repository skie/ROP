<?php
declare(strict_types=1);

namespace Test;

use Exception;
use PHPUnit\Framework\TestCase;
use ROP\Result;
use function ROP\bind;
use function ROP\compose;
use function ROP\doubleMap;
use function ROP\fail;
use function ROP\lift;
use function ROP\map;
use function ROP\ok;
use function ROP\plus;
use function ROP\plusWith;
use function ROP\tee;
use function ROP\tryCatch;
use function ROP\unite;

class FunctionsTest extends TestCase
{
    public function testOkCreatesSuccessResult(): void
    {
        $result = ok(42);

        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(42, $result->getValue());
    }

    public function testFailCreatesErrorResult(): void
    {
        $result = fail('error message');

        $this->assertInstanceOf(Result::class, $result);
        $this->assertFalse($result->isSuccess());
        $this->assertEquals('error message', $result->getError());
    }

    public function testMapTransformsSuccessValue(): void
    {
        $result = ok(2);
        $mapped = map(fn($x) => $x * 2)($result);

        $this->assertTrue($mapped->isSuccess());
        $this->assertEquals(4, $mapped->getValue());
    }

    public function testMapSkipsErrorValue(): void
    {
        $result = fail('error');
        $mapped = map(fn($x) => $x * 2)($result);

        $this->assertFalse($mapped->isSuccess());
        $this->assertEquals('error', $mapped->getError());
    }

    public function testMapWithPipeOperator(): void
    {
        $result = 2
            |> ok(...)
            |> map(fn($x) => $x * 2);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(4, $result->getValue());
    }

    public function testBindChainsSuccessfulOperations(): void
    {
        $result = ok(2);
        $bound = bind(fn($x) => ok($x * 2))($result);

        $this->assertTrue($bound->isSuccess());
        $this->assertEquals(4, $bound->getValue());
    }

    public function testBindPropagatesErrors(): void
    {
        $result = ok(2);
        $bound = bind(fn($x) => fail('operation failed'))($result);

        $this->assertFalse($bound->isSuccess());
        $this->assertEquals('operation failed', $bound->getError());
    }

    public function testBindSkipsErrorInput(): void
    {
        $double = fn($x) => ok($x * 2);
        $result = fail('initial error');
        /** @phpstan-ignore argument.type */
        $bound = bind($double)($result);

        $this->assertFalse($bound->isSuccess());
        $this->assertEquals('initial error', $bound->getError());
    }

    public function testBindWithPipeOperator(): void
    {
        $divide = function ($x) {
            return $x === 0 ? fail('division by zero') : ok(10 / $x);
        };

        $result = 2
            |> ok(...)
            |> bind($divide);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(5, $result->getValue());
    }

    public function testBindWithPipeOperatorError(): void
    {
        $divide = function ($x) {
            return $x === 0 ? fail('division by zero') : ok(10 / $x);
        };

        $result = 0
            |> ok(...)
            |> bind($divide);

        $this->assertFalse($result->isSuccess());
        $this->assertEquals('division by zero', $result->getError());
    }

    public function testTryCatchHandlesExceptions(): void
    {
        $throwingFn = function ($x) {
            throw new Exception('something went wrong');
        };

        $result = ok(2);
        $caught = tryCatch($throwingFn)($result);
        /** @phpstan-ignore deadCode.unreachable */
        $this->assertFalse($caught->isSuccess());
        $this->assertEquals('something went wrong', $caught->getError());
    }

    public function testTryCatchPassesSuccessThrough(): void
    {
        $result = ok(2);
        $caught = tryCatch(fn($x) => $x * 2)($result);

        $this->assertTrue($caught->isSuccess());
        $this->assertEquals(4, $caught->getValue());
    }

    public function testTryCatchSkipsErrorInput(): void
    {
        $result = fail('initial error');
        $caught = tryCatch(fn($x) => $x * 2)($result);

        $this->assertFalse($caught->isSuccess());
        $this->assertEquals('initial error', $caught->getError());
    }

    public function testTryCatchWithPipeOperator(): void
    {
        $riskyOperation = function ($x) {
            if ($x < 0) {
                throw new Exception('negative numbers not allowed');
            }

            return $x * 2;
        };

        $success = 5
            |> ok(...)
            |> tryCatch($riskyOperation);

        $failure = -5
            |> ok(...)
            |> tryCatch($riskyOperation);

        $this->assertTrue($success->isSuccess());
        $this->assertEquals(10, $success->getValue());

        $this->assertFalse($failure->isSuccess());
        $this->assertEquals('negative numbers not allowed', $failure->getError());
    }

    public function testTeeExecutesSideEffects(): void
    {
        $sideEffect = null;
        $result = ok(42);
        $teed = tee(function ($x) use (&$sideEffect) {
            $sideEffect = $x;
        })($result);

        $this->assertEquals(42, $sideEffect);
        $this->assertTrue($teed->isSuccess());
        $this->assertEquals(42, $teed->getValue());
    }

    public function testTeeSkipsErrorTrack(): void
    {
        $sideEffect = null;
        $result = fail('error');
        $teed = tee(function ($x) use (&$sideEffect) {
            $sideEffect = $x;
        })($result);

        $this->assertNull($sideEffect);
        $this->assertFalse($teed->isSuccess());
        $this->assertEquals('error', $teed->getError());
    }

    public function testTeeWithPipeOperator(): void
    {
        $log = [];

        $result = 2
            |> ok(...)
            |> tee(function ($x) use (&$log) {
                $log[] = "step 1: $x";
            })
            |> map(fn($x) => $x * 2)
            |> tee(function ($x) use (&$log) {
                $log[] = "step 2: $x";
            });

        $this->assertEquals(['step 1: 2', 'step 2: 4'], $log);
        $this->assertEquals(4, $result->getValue());
    }

    public function testDoubleMapTransformsBothTracks(): void
    {
        $formatSuccess = fn($x) => ['value' => $x, 'status' => 'success'];
        $formatError = fn($e) => ['error' => $e, 'status' => 'failed'];

        $success = ok(42);
        $mappedSuccess = doubleMap($formatSuccess, $formatError)($success);

        $failure = fail('something wrong');
        $mappedFailure = doubleMap($formatSuccess, $formatError)($failure);

        $this->assertTrue($mappedSuccess->isSuccess());
        $this->assertEquals(['value' => 42, 'status' => 'success'], $mappedSuccess->getValue());

        $this->assertFalse($mappedFailure->isSuccess());
        $this->assertEquals(['error' => 'something wrong', 'status' => 'failed'], $mappedFailure->getError());
    }

    public function testDoubleMapWithPipeOperator(): void
    {
        $formatSuccess = fn($x) => "Success: $x";
        $formatError = fn($e) => "Error: $e";

        $success = 42
            |> ok(...)
            |> doubleMap($formatSuccess, $formatError);

        $failure = 'oops'
            |> fail(...)
            |> doubleMap($formatSuccess, $formatError);

        $this->assertTrue($success->isSuccess());
        $this->assertEquals('Success: 42', $success->getValue());

        $this->assertFalse($failure->isSuccess());
        $this->assertEquals('Error: oops', $failure->getError());
    }

    public function testComposeCreatesPipeline(): void
    {
        $pipeline = compose(
            map(fn($x) => $x * 2),
            map(fn($x) => $x + 1),
            map(fn($x) => $x * 3),
        );

        $result = $pipeline(ok(5));

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(33, $result->getValue());
    }

    public function testComposeWithMixedOperations(): void
    {
        $divide = function ($x) {
            return $x === 0 ? fail('division by zero') : ok(100 / $x);
        };

        $pipeline = compose(
            map(fn($x) => $x + 1),
            bind($divide),
            map(fn($x) => (int)$x),
        );

        $success = $pipeline(ok(4));
        $failure = $pipeline(ok(-1));

        $this->assertTrue($success->isSuccess());
        $this->assertEquals(20, $success->getValue());

        $this->assertFalse($failure->isSuccess());
        $this->assertEquals('division by zero', $failure->getError());
    }

    public function testComplexPipelineWithAllOperations(): void
    {
        $log = [];

        $validatePositive = function ($x) {
            return $x > 0 ? ok($x) : fail('must be positive');
        };

        $safeDivide = function ($x) {
            if ($x > 100) {
                throw new Exception('number too large');
            }

            return 100 / $x;
        };

        $formatSuccess = fn($x) => ['result' => $x, 'success' => true];
        $formatError = fn($e) => ['error' => $e, 'success' => false];

        $result = 10
            |> ok(...)
            |> tee(function ($x) use (&$log) {
                $log[] = "input: $x";
            })
            |> bind($validatePositive)
            |> map(fn($x) => $x * 2)
            |> tee(function ($x) use (&$log) {
                $log[] = "after multiply: $x";
            })
            |> tryCatch($safeDivide)
            |> tee(function ($x) use (&$log) {
                $log[] = "after divide: $x";
            })
            |> doubleMap($formatSuccess, $formatError);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(['result' => 5, 'success' => true], $result->getValue());
        $this->assertEquals(['input: 10', 'after multiply: 20', 'after divide: 5'], $log);
    }

    public function testComplexPipelineWithError(): void
    {
        $log = [];

        $validatePositive = function ($x) {
            return $x > 0 ? ok($x) : fail('must be positive');
        };

        $result = -5
            |> ok(...)
            |> tee(function ($x) use (&$log) {
                $log[] = "input: $x";
            })
            |> bind($validatePositive)
            |> tee(function ($x) use (&$log) {
                $log[] = 'this should not run';
            })
            |> map(fn($x) => ['result' => $x, 'success' => true]);

        $this->assertFalse($result->isSuccess());
        $this->assertEquals(['input: -5'], $log);
    }

    public function testRealWorldUserRegistrationExample(): void
    {
        $validateEmail = function ($email) {
            return filter_var($email, FILTER_VALIDATE_EMAIL)
                ? ok($email)
                : fail('invalid email');
        };

        $checkEmailExists = function ($email) {
            return $email === 'taken@example.com'
                ? fail('email already exists')
                : ok($email);
        };

        $createUser = function ($email) {
            return ['id' => 123, 'email' => $email, 'created_at' => '2025-01-01'];
        };

        $formatSuccess = fn($user) => ['success' => true, 'user' => $user];
        $formatError = fn($error) => ['success' => false, 'message' => $error];

        $successResult = 'user@example.com'
            |> ok(...)
            |> bind($validateEmail)
            |> bind($checkEmailExists)
            |> tryCatch($createUser)
            |> doubleMap($formatSuccess, $formatError);

        $invalidEmailResult = 'invalid-email'
            |> ok(...)
            |> bind($validateEmail)
            |> bind($checkEmailExists)
            |> tryCatch($createUser)
            |> doubleMap($formatSuccess, $formatError);

        $takenEmailResult = 'taken@example.com'
            |> ok(...)
            |> bind($validateEmail)
            |> bind($checkEmailExists)
            |> tryCatch($createUser)
            |> doubleMap($formatSuccess, $formatError);

        $this->assertTrue($successResult->isSuccess());
        $successData = $successResult->getValue();
        $this->assertIsArray($successData);
        $this->assertArrayHasKey('user', $successData);
        $this->assertEquals('user@example.com', $successData['user']['email']);

        $this->assertFalse($invalidEmailResult->isSuccess());
        $invalidError = $invalidEmailResult->getError();
        $this->assertIsArray($invalidError);
        $this->assertArrayHasKey('message', $invalidError);
        $this->assertEquals('invalid email', $invalidError['message']);

        $this->assertFalse($takenEmailResult->isSuccess());
        $takenError = $takenEmailResult->getError();
        $this->assertIsArray($takenError);
        $this->assertArrayHasKey('message', $takenError);
        $this->assertEquals('email already exists', $takenError['message']);
    }

    public function testLiftConvertsRegularFunction(): void
    {
        $double = fn($x) => $x * 2;
        $liftedDouble = lift($double);

        $result = $liftedDouble(21);

        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(42, $result->getValue());
    }

    public function testLiftWithPipeOperator(): void
    {
        $triple = fn($x) => $x * 3;

        $result = 14
            |> ok(...)
            |> bind(lift($triple));

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(42, $result->getValue());
    }

    public function testPlusCombinesTwoSuccessResults(): void
    {
        $r1 = ok(10);
        $r2 = ok(32);

        $result = plus(
            fn($a, $b) => $a + $b,
            fn($errors) => implode(', ', $errors),
            $r1,
            $r2,
        );

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(42, $result->getValue());
    }

    public function testPlusCombinesErrors(): void
    {
        $r1 = fail('error 1');
        $r2 = fail('error 2');

        $result = plus(
            fn($a, $b) => $a + $b,
            fn($errors) => implode(', ', $errors),
            $r1,
            $r2,
        );

        $this->assertFalse($result->isSuccess());
        $this->assertEquals('error 1, error 2', $result->getError());
    }

    public function testPlusWithOneError(): void
    {
        $r1 = ok(10);
        $r2 = fail('calculation failed');

        $result = plus(
            fn($a, $b) => $a + $b,
            fn($errors) => implode(', ', $errors),
            $r1,
            $r2,
        );

        $this->assertFalse($result->isSuccess());
        $this->assertEquals('calculation failed', $result->getError());
    }

    public function testPlusWithArrayErrors(): void
    {
        $r1 = fail(['field1' => 'invalid']);
        $r2 = fail(['field2' => 'required']);

        $result = plus(
            fn($a, $b) => array_merge($a, $b),
            fn($errors) => $errors,
            $r1,
            $r2,
        );

        $this->assertFalse($result->isSuccess());
        $this->assertEquals(['field1' => 'invalid', 'field2' => 'required'], $result->getError());
    }

    public function testPlusWithPipeOperator(): void
    {
        $fetchUser = fn($id) => ok(['id' => $id, 'name' => 'John']);
        $fetchProfile = fn($id) => ok(['avatar' => 'john.jpg']);

        $result = 123
            |> ok(...)
            |> bind($fetchUser)
            |> plusWith(
                fn($user, $profile) => [...$user, ...$profile],
                fn($errors) => implode(', ', $errors),
                $fetchProfile(123),
            );

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(['id' => 123, 'name' => 'John', 'avatar' => 'john.jpg'], $result->getValue());
    }

    public function testUniteReturnsSecondOnSuccess(): void
    {
        $r1 = ok('first');
        $r2 = ok('second');

        $result = $r1 |> unite($r2);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals('second', $result->getValue());
    }

    public function testUniteReturnsFirstOnError(): void
    {
        $r1 = fail('first error');
        $r2 = ok('second');

        $result = $r1 |> unite($r2);

        $this->assertFalse($result->isSuccess());
        $this->assertEquals('first error', $result->getError());
    }

    public function testUniteChain(): void
    {
        $checkRequired = fn($value) => empty($value) ? fail('required') : ok($value);

        $checkLength = fn($value) => strlen($value) < 3 ? fail('too short') : ok($value);

        $checkFormat = fn($value) => !preg_match('/^[a-z]+$/', $value) ? fail('invalid format') : ok($value);

        $result = 'hello'
            |> ok(...)
            |> bind($checkRequired)
            |> unite(ok('hello') |> bind($checkLength))
            |> unite(ok('hello') |> bind($checkFormat));

        $this->assertTrue($result->isSuccess());
        $this->assertEquals('hello', $result->getValue());
    }

    public function testUniteStopsOnFirstError(): void
    {
        $checkRequired = fn($value) => ok($value);
        $checkLength = fn($value) => fail('too short');
        $checkFormat = fn($value) => fail('invalid format');

        $result = 'ab'
            |> ok(...)
            |> bind($checkRequired)
            |> unite(ok('ab') |> bind($checkLength))
            |> unite(ok('ab') |> bind($checkFormat));

        $this->assertFalse($result->isSuccess());
        $this->assertEquals('too short', $result->getError());
    }

    public function testCompleteWorkflowWithAllFunctions(): void
    {
        $log = [];

        $validate = fn($x) => $x > 0 ? ok($x) : fail('must be positive');
        $double = fn($x) => $x * 2;
        $checkMax = fn($x) => $x > 100 ? fail('too large') : ok($x);

        $result = 21
            |> ok(...)
            |> bind($validate)
            |> tee(function ($x) use (&$log) {
                $log[] = "validated: $x";
            })
            |> bind(lift($double))
            |> tee(function ($x) use (&$log) {
                $log[] = "doubled: $x";
            })
            |> bind($checkMax)
            |> doubleMap(
                fn($x) => ['success' => true, 'value' => $x],
                fn($e) => ['success' => false, 'error' => $e],
            );

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(['success' => true, 'value' => 42], $result->getValue());
        $this->assertEquals(['validated: 21', 'doubled: 42'], $log);
    }
}
