<?php
declare(strict_types=1);

namespace ROP;

use Throwable;

/**
 * Railway Oriented Programming helper functions for pipe-based data flow
 *
 * These functions follow Scott Wlaschin's Railway Oriented Programming pattern,
 * adapted for PHP 8.5 pipe operator usage. Each function is designed to work
 * with the pipe operator for clean, linear data flow.
 */

/**
 * Create successful result (constructor)
 *
 * Converts a single-track value into a two-track Result on success path
 *
 * @template TSuccess
 * @param TSuccess $value Success value
 * @return \ROP\Result<TSuccess, never> Success result
 */
function ok(mixed $value): Result
{
    return Result::success($value);
}

/**
 * Create successful result (constructor)
 *
 * Converts a single-track value into a two-track Result on success path
 *
 * @template TSuccess
 * @param TSuccess $value Success value
 * @return \ROP\Result<TSuccess, never> Success result
 */
function of(mixed $value): Result
{
    return Result::success($value);
}

/**
 * Create error result (constructor)
 *
 * Converts a single-track value into a two-track Result on failure path
 *
 * @template TError
 * @param TError $error Error value
 * @return \ROP\Result<never, TError> Error result
 */
function fail(mixed $error): Result
{
    return Result::failure($error);
}

/**
 * Map adapter - converts 1-1 function to 2-2 function
 *
 * Takes a regular one-track function and makes it work with Result types.
 * If Result is success, applies function and wraps result in success.
 * If Result is error, bypasses function and returns error unchanged.
 *
 * @template TValue
 * @template TError
 * @template TResult
 * @param callable(TValue): TResult $fn One-track function to map
 * @return callable(\ROP\Result<TValue, TError>): \ROP\Result<TResult, TError> Function that accepts Result and returns Result
 */
function map(callable $fn): callable
{
    return function (Result $result) use ($fn): Result {
        return $result->isSuccess()
            ? Result::success($fn($result->getValue()))
            : $result;
    };
}

/**
 * Bind adapter - converts 1-2 switch function to 2-2 function
 *
 * Takes a switch function (that returns Result) and makes it accept Result input.
 * This is the "diagonal" or "switch" adapter that's core to ROP.
 * If Result is success, unwraps value and passes to switch function.
 * If Result is error, bypasses function and returns error unchanged.
 *
 * @template TValue
 * @template TError
 * @template TResult
 * @param callable(TValue): \ROP\Result<TResult, TError> $fn Switch function (returns Result)
 * @return callable(\ROP\Result<TValue, TError>): \ROP\Result<TResult, TError> Function that accepts Result and returns Result
 */
function bind(callable $fn): callable
{
    return function (Result $result) use ($fn): Result {
        return $result->isSuccess()
            ? $fn($result->getValue())
            : $result;
    };
}

/**
 * TryCatch adapter - converts exception-throwing 1-1 to 1-2, then to 2-2
 *
 * Takes a function that might throw exceptions and converts it to Result-based flow.
 * Catches exceptions and converts them to error Results.
 * If Result is success, executes function with exception handling.
 * If Result is error, bypasses function and returns error unchanged.
 *
 * @template TValue
 * @template TError
 * @template TResult
 * @param callable(TValue): TResult $fn Function that might throw exceptions
 * @return callable(\ROP\Result<TValue, TError>): \ROP\Result<TResult, TError|string> Function that accepts Result and returns Result
 */
function tryCatch(callable $fn): callable
{
    return function (Result $result) use ($fn): Result {
        if (!$result->isSuccess()) {
            return $result;
        }

        try {
            return Result::success($fn($result->getValue()));
        } catch (Throwable $e) {
            return Result::failure($e->getMessage());
        }
    };
}

/**
 * Tee adapter - converts dead-end function to pass-through
 *
 * Executes function for side effects (logging, debugging) without affecting flow.
 * Returns original value unchanged, allowing it to continue down the pipeline.
 * Only executes on success track, skips on error track.
 *
 * @template TValue
 * @template TError
 * @param callable(TValue): void $fn Side-effect function
 * @return callable(\ROP\Result<TValue, TError>): \ROP\Result<TValue, TError> Function that accepts Result and returns Result unchanged
 */
function tee(callable $fn): callable
{
    return function (Result $result) use ($fn): Result {
        if ($result->isSuccess()) {
            $fn($result->getValue());
        }

        return $result;
    };
}

/**
 * DoubleMap adapter - handles both success and failure tracks
 *
 * Takes two functions and applies appropriate one based on Result state.
 * Allows transformation of both success and failure values.
 *
 * @template TValue
 * @template TError
 * @template TSuccess
 * @template TFailure
 * @param callable(TValue): TSuccess $successFn Function for success track
 * @param callable(TError): TFailure $failureFn Function for failure track
 * @return callable(\ROP\Result<TValue, TError>): \ROP\Result<TSuccess, TFailure> Function that accepts Result and returns Result
 */
function doubleMap(callable $successFn, callable $failureFn): callable
{
    return function (Result $result) use ($successFn, $failureFn): Result {
        return $result->isSuccess()
            ? Result::success($successFn($result->getValue()))
            : Result::failure($failureFn($result->getError()));
    };
}

/**
 * Compose functions left-to-right for reusable pipelines
 *
 * Creates a new function by composing multiple functions together.
 * Unlike pipes (which execute immediately), composition builds a reusable function.
 *
 * @param callable ...$fns Functions to compose
 * @return callable Composed function
 */
function compose(callable ...$fns): callable
{
    return function (mixed $value) use ($fns): mixed {
        return array_reduce($fns, fn($carry, $fn) => $fn($carry), $value);
    };
}

/**
 * Lifts a regular function into Result context
 *
 * Converts a one-track function into a switch function that returns Result.
 * Perfect for converting regular functions to work with bind().
 *
 * @template T
 * @template R
 * @param callable(T): R $fn Regular function to lift
 * @return callable(T): \ROP\Result<R, never> Lifted function that returns Result
 */
function lift(callable $fn): callable
{
    return function (mixed $value) use ($fn): Result {
        return Result::success($fn($value));
    };
}

/**
 * Combines two Results in parallel
 *
 * If both Results are success, combines their values using successFunc.
 * If either Result is error, collects all errors and combines using failureFunc.
 *
 * @template T1
 * @template T2
 * @template E1
 * @template E2
 * @param callable(T1, T2): mixed $successFunc Function to combine success values
 * @param callable(array<int|string, mixed>): mixed $failureFunc Function to combine error values
 * @param \ROP\Result<T1, E1> $r1 First Result
 * @param \ROP\Result<T2, E2> $r2 Second Result
 * @return \ROP\Result<mixed, mixed> Combined Result
 */
function plus(callable $successFunc, callable $failureFunc, Result $r1, Result $r2): Result
{
    if ($r1->isSuccess() && $r2->isSuccess()) {
        return Result::success($successFunc(
            $r1->getValue(),
            $r2->getValue(),
        ));
    }

    $errors = [];
    if (!$r1->isSuccess()) {
        if (is_array($r1->getError())) {
            $errors = $r1->getError();
        } else {
            $errors[] = $r1->getError();
        }
    }
    if (!$r2->isSuccess()) {
        if (is_array($r2->getError())) {
            $errors = array_merge($errors, $r2->getError());
        } else {
            $errors[] = $r2->getError();
        }
    }

    return Result::failure($failureFunc($errors));
}

/**
 * Instance method version of plus for pipelines
 *
 * Combines current Result with another in parallel.
 * Returns a callable that accepts a Result and combines it with $other.
 *
 * @template T2
 * @template E2
 * @param callable(mixed, T2): mixed $successFunc Function to combine success values
 * @param callable(array<int|string, mixed>): mixed $failureFunc Function to combine error values
 * @param \ROP\Result<T2, E2> $other Result to combine with
 * @return callable(\ROP\Result<mixed, mixed>): \ROP\Result<mixed, mixed> Function that combines Results
 */
function plusWith(callable $successFunc, callable $failureFunc, Result $other): callable
{
    return function (Result $result) use ($successFunc, $failureFunc, $other): Result {
        return plus($successFunc, $failureFunc, $result, $other);
    };
}

/**
 * Tap adapter - execute side effects on Result itself
 *
 * Similar to tee() but operates on the Result itself rather than unwrapped value.
 * Executes function for inspection/debugging of both success and error tracks.
 * Returns original Result unchanged, allowing it to continue down the pipeline.
 * Useful for logging, debugging, or monitoring both success and failure states.
 *
 * @template TValue
 * @template TError
 * @param callable(\ROP\Result<TValue, TError>): void $fn Side-effect function that receives Result
 * @return callable(\ROP\Result<TValue, TError>): \ROP\Result<TValue, TError> Function that accepts Result and returns Result unchanged
 */
function tap(callable $fn): callable
{
    return function (Result $result) use ($fn): Result {
        $fn($result);

        return $result;
    };
}

/**
 * Unites two Results sequentially
 *
 * If first Result succeeds, returns the second Result.
 * If first Result fails, returns the first Result (with error).
 * Useful for sequential validation chains where you only care about the final result.
 *
 * @template T2
 * @template E2
 * @param \ROP\Result<T2, E2> $other Second Result to return if first succeeds
 * @return callable(\ROP\Result<mixed, mixed>): \ROP\Result<T2, mixed> Function that unites Results
 */
function unite(Result $other): callable
{
    return function (Result $result) use ($other): Result {
        return $result->isSuccess() ? $other : $result;
    };
}
