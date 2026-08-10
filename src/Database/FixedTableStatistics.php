<?php

declare(strict_types=1);

namespace Difflock\Database;

use Difflock\Contracts\TableStatistics;

/**
 * Table sizes supplied by hand.
 *
 * Part of the public API on purpose: writing a test for a custom rule should not
 * require a populated database, and this is how you hand a rule "pretend `orders`
 * has eight million rows".
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class FixedTableStatistics implements TableStatistics
{
    /**
     * @param  array<string, int>  $rows
     * @param  array<string, int>  $bytes
     */
    public function __construct(
        private array $rows = [],
        private array $bytes = [],
        private bool $approximate = false,
    ) {}

    public function rows(string $table): ?int
    {
        return $this->rows[$table] ?? null;
    }

    public function bytes(string $table): ?int
    {
        return $this->bytes[$table] ?? null;
    }

    public function approximate(): bool
    {
        return $this->approximate;
    }
}
