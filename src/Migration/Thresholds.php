<?php

declare(strict_types=1);

namespace Difflock\Migration;

/**
 * The row counts at which the rules start treating a table as big enough to matter.
 *
 * These are the only numbers in Difflock's risk model, and they are configuration
 * rather than judgement: a hundred thousand rows is nothing on one deployment and a
 * long lock on another. The defaults are deliberately conservative — it costs a
 * reviewer thirty seconds to dismiss a finding, and it costs an outage to miss one.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class Thresholds
{
    public function __construct(
        public int $mediumTableRows = 100_000,
        public int $largeTableRows = 1_000_000,
    ) {}

    /** Null rows means unknown, which is never "large". */
    public function isLarge(?int $rows): bool
    {
        return $rows !== null && $rows >= $this->largeTableRows;
    }

    public function isMedium(?int $rows): bool
    {
        return $rows !== null && $rows >= $this->mediumTableRows;
    }

    /** Rows formatted the way the console prints them: `4,921,000`. */
    public static function format(int $rows): string
    {
        return number_format($rows);
    }
}
