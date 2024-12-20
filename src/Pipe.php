<?php
declare(strict_types=1);

namespace ROP;

use Throwable;

/**
 * Function composition utilities
 */
class Pipe
{
    /**
     * Pipe constructor
     *
     * @param mixed $value The current value in the pipe
     */
    private function __construct(
        private mixed $value
    ) {
    }

    /**
     * Creates a new pipe with initial value
     *
     * @param mixed $value Initial value
     * @return self New Pipe instance
     */
    public static function from(mixed $value): self
    {
        return new self($value);
    }

    /**
     * Applies a function to the current value
     *
     * @param callable $fn Function to apply
     * @return self New Pipe instance
     */
    public function pipe(callable $fn): self
    {
        return new self($fn($this->value));
    }

    /**
     * Gets the final value from the pipe
     *
     * @return mixed The final value
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * Composes multiple functions into a single function that flows left-to-right
     *
     * @param callable ...$fns Functions to compose
     * @return callable The composed function
     */
    public static function flow(callable ...$fns): callable
    {
        return function ($x) use ($fns) {
            return array_reduce($fns, fn ($acc, $fn) => $fn($acc), $x);
        };
    }

    /**
     * Composes multiple functions into a single function that flows right-to-left
     *
     * @param callable ...$fns Functions to compose in reverse order
     * @return callable The composed function
     */
    public static function compose(callable ...$fns): callable
    {
        return self::flow(...array_reverse($fns));
    }

    /**
     * Lifts a plain function into the Railway context
     *
     * @param callable $fn Function to lift
     * @return callable Function that returns a Railway
     */
    public static function lift(callable $fn): callable
    {
        return function ($value) use ($fn) {
            try {
                $result = $fn($value);

                return Railway::of($result);
            } catch (Throwable $e) {
                return Railway::fail($e->getMessage());
            }
        };
    }
}
