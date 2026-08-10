<?php

declare(strict_types=1);

namespace Difflock\Migration\Parser;

/**
 * One statement inside a schema closure: the blueprint method that opens the chain,
 * and everything chained onto it.
 *
 * `$table->string('email', 320)->nullable()->change()` is one operation named
 * `string` with two modifiers. The split matters because the *operation* says what
 * kind of thing is being defined and the *modifiers* say what is being done to it —
 * `change()` in that chain is the entire difference between adding a column and
 * rewriting one.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class Operation
{
    /**
     * @param  list<mixed>  $arguments  Literal values, or {@see Unresolved} where the parser could not reduce one.
     * @param  list<Modifier>  $modifiers  In the order they were chained.
     * @param  bool  $conditional  Whether this sits inside an `if`, a loop, or a `try` — so it may not run.
     */
    public function __construct(
        public string $method,
        public array $arguments,
        public array $modifiers,
        public int $line,
        public bool $conditional = false,
    ) {}

    public function hasModifier(string ...$names): bool
    {
        foreach ($this->modifiers as $modifier) {
            if (in_array($modifier->method, $names, true)) {
                return true;
            }
        }

        return false;
    }

    public function modifier(string $name): ?Modifier
    {
        foreach ($this->modifiers as $modifier) {
            if ($modifier->method === $name) {
                return $modifier;
            }
        }

        return null;
    }

    public function argument(int $index): mixed
    {
        return $this->arguments[$index] ?? null;
    }

    public function stringArgument(int $index): ?string
    {
        $value = $this->argument($index);

        return is_string($value) ? $value : null;
    }

    public function intArgument(int $index): ?int
    {
        $value = $this->argument($index);

        return is_int($value) ? $value : null;
    }

    /**
     * The column names this operation names, from an argument that is either one
     * string or a list of them.
     *
     * Only the ones the parser could actually read. `dropColumn($a, 'b')` yields
     * `['b']`, and {@see self::fullyResolved()} is what tells a rule that the list is
     * short of the truth.
     *
     * @return list<string>
     */
    public function columns(int $index = 0): array
    {
        $value = $this->argument($index);

        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $columns = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $columns[] = $item;
            }
        }

        return $columns;
    }

    /**
     * Every column named across every argument, for the methods that take a variadic
     * list — `dropColumn('a', 'b', 'c')` is as legal as `dropColumn(['a', 'b', 'c'])`.
     *
     * @return list<string>
     */
    public function allColumns(): array
    {
        $columns = [];

        foreach (array_keys($this->arguments) as $index) {
            foreach ($this->columns($index) as $column) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /** Whether every argument reduced to a value, so a rule may speak precisely. */
    public function fullyResolved(): bool
    {
        foreach ($this->arguments as $argument) {
            if ($argument instanceof Unresolved) {
                return false;
            }

            if (! is_array($argument)) {
                continue;
            }

            foreach ($argument as $item) {
                if ($item instanceof Unresolved) {
                    return false;
                }
            }
        }

        return true;
    }
}
