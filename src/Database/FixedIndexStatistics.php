<?php

declare(strict_types=1);

namespace Difflock\Database;

use Difflock\Contracts\IndexStatistics;

/**
 * Index usage supplied by hand, and the null object for engines that keep none.
 *
 * Part of the public API for the same reason as {@see FixedTableStatistics}: a rule
 * that reasons about index usage should be testable without a database that has
 * been running long enough to have any.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class FixedIndexStatistics implements IndexStatistics
{
    /**
     * @param  array<string, int>  $scans  Keyed `table.index`.
     */
    public function __construct(
        private array $scans = [],
        private ?int $days = null,
    ) {}

    public function scans(string $table, string $index): ?int
    {
        return $this->scans[$table.'.'.$index] ?? null;
    }

    public function observedDays(): ?int
    {
        return $this->days;
    }
}
