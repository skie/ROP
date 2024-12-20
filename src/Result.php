<?php
declare(strict_types=1);

namespace ROP;

/**
 * Result type for Railway Oriented Programming
 *
 * Represents either a success value or an error value.
 * Used as the underlying container for Railway monad.
 *
 * @template-covariant TValue The type of the success value
 * @template-covariant TError The type of the error value
 */
class Result
{
    /**
     * @param TValue|null $value The success value
     * @param TError|null $error The error value, null if success
     */
    private function __construct(
        private readonly mixed $value,
        private readonly mixed $error = null
    ) {
    }

    /**
     * Creates a new Result in success state
     *
     * @template T
     * @param T $value The success value
     * @return \ROP\Result<T, mixed> New Result instance
     */
    public static function success(mixed $value): self
    {
        return new self($value);
    }

    /**
     * Creates a new Result in failure state
     *
     * @template E
     * @param E $error The error value
     * @return \ROP\Result<mixed, E> New Result instance
     */
    public static function failure(mixed $error): self
    {
        return new self(null, $error);
    }

    /**
     * Checks if the Result is in success state
     *
     * @return bool True if success (no error), false otherwise
     */
    public function isSuccess(): bool
    {
        return $this->error === null;
    }

    /**
     * Gets the success value
     *
     * @return TValue|null The success value
     */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * Gets the error value
     *
     * @return TError|null The error value
     */
    public function getError(): mixed
    {
        return $this->error;
    }
}
