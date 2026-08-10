<?php

declare(strict_types=1);

namespace Difflock\Migration\Parser;

/**
 * One call chained onto a column definition: `->nullable()`, `->default('x')`,
 * `->change()`, `->cascadeOnDelete()`.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class Modifier
{
    /**
     * @param  list<mixed>  $arguments  Literal values, or {@see Unresolved} where the parser could not reduce one.
     */
    public function __construct(
        public string $method,
        public array $arguments = [],
    ) {}

    public function argument(int $index): mixed
    {
        return $this->arguments[$index] ?? null;
    }
}
