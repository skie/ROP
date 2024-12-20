<?php
declare(strict_types=1);

namespace ROP;

use RuntimeException;
use Throwable;

/**
 * Railway monad with method chaining support
 *
 * Implements Railway Oriented Programming pattern for elegant error handling.
 * Treats the flow of data like a railway track with two lines:
 * - Success track (happy path)
 * - Failure track (error path)
 *
 * @template-covariant TValue
 * @template-covariant TError
 */
class Railway
{
    /**
     * @param \ROP\Result<TValue, TError> $result The underlying Result object containing success/failure state
     */
    protected function __construct(
        private readonly Result $result
    ) {
    }

    /**
     * Creates a Railway instance from an existing Result
     *
     * @template T
     * @template E
     * @param \ROP\Result<T, E> $result The Result object to wrap
     * @return self<T, E> New Railway instance
     */
    public static function fromResult(Result $result): self
    {
        return new self($result);
    }

    /**
     * Checks if the Railway is in success state
     */
    public function isSuccess(): bool
    {
        return $this->result->getError() === null;
    }

    /**
     * Gets the success value
     *
     * @throws \RuntimeException when in failure state
     * @return TValue The success value
     */
    public function getValue(): mixed
    {
        if (!$this->isSuccess()) {
            throw new RuntimeException('Cannot get value from failure state');
        }

        /** @var TValue */
        return $this->result->getValue();
    }

    /**
     * Gets the error value
     *
     * @throws \RuntimeException when in success state
     * @return TError The error value
     */
    public function getError(): mixed
    {
        if ($this->isSuccess()) {
            throw new RuntimeException('Cannot get error from success state');
        }

        /** @var TError */
        return $this->result->getError();
    }

    /**
     * Creates a new Railway in success state
     * One track function: 1-1
     *
     * @template T
     * @param T $value The success value
     * @return self<T, never> New Railway instance
     */
    public static function of(mixed $value): self
    {
        return new self(Result::success($value));
    }

    /**
     * Creates a new Railway in failure state
     * One track function: 1-1
     *
     * @template E
     * @param E $error The error value
     * @return self<never, E> New Railway instance
     */
    public static function fail(mixed $error): self
    {
        return new self(Result::failure($error));
    }

    /**
     * Converts one track function into switch
     * Similar to bind but for one-track functions
     * 1-1 : 1-2
     *
     * @param callable $fn The function to lift
     * @return callable A function that returns a Railway
     */
    public static function lift(callable $fn): callable
    {
        return function ($value) use ($fn) {
            return self::of($fn($value));
        };
    }

    /**
     * Converts switch into two track function
     * Chains operations while handling errors
     * 1-2 : 2-2
     *
     * @param callable(TValue): (\ROP\Railway|mixed) $fn The function to bind
     * @return self<mixed, TError> New Railway instance
     */
    public function bind(callable $fn): self
    {
        if (!$this->isSuccess()) {
            return $this;
        }

        try {
            /** @var TValue $value */
            $value = $this->getValue();
            $result = $fn($value);
            if ($result instanceof Result) {
                return new self($result);
            }
            if ($result instanceof self) {
                return $result;
            }

            return self::of($result);
        } catch (Throwable $e) {
            /** @var self<mixed, TError> */
            return self::fail($e->getMessage());
        }
    }

    /**
     * Converts one track function into two track function
     * Transform success values
     * 1-1 : 2-2
     *
     * @param callable(TValue): mixed $fn The function to map
     * @return \ROP\Railway<mixed, TError> New Railway instance
     */
    public function map(callable $fn): self
    {
        return $this->bind(fn ($value) => self::of($fn($value)));
    }

    /**
     * Dead-end function for side effects
     * Executes function without affecting the railway
     *
     * @param callable(TValue): mixed $fn The side-effect function
     * @return self<TValue, TError> Same Railway instance
     */
    public function tee(callable $fn): self
    {
        if ($this->isSuccess()) {
            $fn($this->getValue());
        }

        return $this;
    }

    /**
     * Handles both tracks, converting one track into two track function
     * Maps both success and failure paths
     * 1-1 : 2-2
     *
     * @param callable(TValue): mixed $successFunc Function to handle success case
     * @param callable(TError): mixed $failureFunc Function to handle failure case
     * @return self<mixed, mixed> New Railway instance
     */
    public function doubleMap(callable $successFunc, callable $failureFunc): self
    {
        if ($this->isSuccess()) {
            return self::of($successFunc($this->getValue()));
        }

        return self::fail($failureFunc($this->getError()));
    }

    /**
     * Handling exceptions with custom error handler
     * Convert one track into switch
     * 1-1 : 1-2
     *
     * @param callable(TValue): mixed $fn The function that might throw
     * @param (callable(\Throwable): mixed)|null $exHandler Optional exception handler
     * @return \ROP\Railway<mixed, mixed> New Railway instance
     */
    public function tryCatch(callable $fn, ?callable $exHandler = null): self
    {
        if (!$this->result->isSuccess()) {
            return $this;
        }

        try {
            /** @var TValue $value */
            $value = $this->getValue();
            $result = $fn($value);

            return $result instanceof self ? $result : self::of($result);
        } catch (Throwable $e) {
            $error = $exHandler ? $exHandler($e) : $e;

            return self::fail($error);
        }
    }

    /**
     * Creates a function that handles exceptions
     * Convert one track into switch
     * 1-1 : 1-2
     *
     * @param callable $fn The function that might throw
     * @param callable|null $exHandler Optional exception handler
     * @return callable A function that returns a Railway
     */
    public static function tryWith(callable $fn, ?callable $exHandler = null): callable
    {
        return function ($value) use ($fn, $exHandler) {
            try {
                $result = $fn($value);

                return $result instanceof self ? $result : self::of($result);
            } catch (Throwable $e) {
                $error = $exHandler ? $exHandler($e) : $e;

                return self::fail($error);
            }
        };
    }

    /**
     * Combines switch functions in parallel
     * 1-2 + 1-2 : 1-2
     *
     * @template T1
     * @template T2
     * @template E1
     * @template E2
     * @param callable(T1, T2): mixed $successFunc Function to combine success values
     * @param callable(array<int|string, mixed>): mixed $failureFunc Function to combine error values
     * @param \ROP\Railway<T1, E1> $r1 First Railway
     * @param \ROP\Railway<T2, E2> $r2 Second Railway
     * @return self<mixed, mixed> New Railway instance
     */
    public static function plus(callable $successFunc, callable $failureFunc, Railway $r1, Railway $r2): self
    {
        if ($r1->isSuccess() && $r2->isSuccess()) {
            return self::of($successFunc(
                $r1->getValue(),
                $r2->getValue()
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
                $errors = array_merge($errors, $r1->getError());
            } else {
                $errors[] = $r2->getError();
            }
        }

        return self::fail($failureFunc($errors));
    }

    /**
     * Instance method version of plus
     * Combines this Railway with another in parallel
     * 1-2 + 1-2 : 1-2
     *
     * @template T2
     * @template E2
     * @param callable(TValue, T2): mixed $successFunc Function to combine success values
     * @param callable(array<int|string, mixed>): mixed $failureFunc Function to combine error values
     * @param \ROP\Railway<T2, E2> $other Railway to combine with
     * @return self<mixed, mixed> New Railway instance
     */
    public function plusWith(callable $successFunc, callable $failureFunc, Railway $other): self
    {
        return self::plus($successFunc, $failureFunc, $this, $other);
    }

    /**
     * Join two switches into another switch
     * 1-2 and 1-2 : 1-2
     *
     * @template T2
     * @template E2
     * @param \ROP\Railway<T2, E2> $other The Railway to unite with
     * @return self<T2, TError|E2> New Railway instance
     */
    public function unite(Railway $other): self
    {
        if ($this->isSuccess()) {
            return $other;
        }

        return $this;
    }

    /**
     * Pattern matches on the Railway state
     * Executes success or failure function based on state
     *
     * @param callable(TValue): mixed $success Function to handle success case
     * @param callable(TError): mixed $failure Function to handle failure case
     * @return mixed Result of either success or failure function
     */
    public function match(callable $success, callable $failure): mixed
    {
        return $this->isSuccess()
            ? $success($this->getValue())
            : $failure($this->getError());
    }
}
