<?php

declare(strict_types=1);

namespace Difflock\Contracts;

/**
 * How much use the database has actually made of an index.
 *
 * This is the one thing that turns the drop-index rule from a hedge into an answer.
 * Without it Difflock can only say it has no view of your query workload; with it
 * the engine's own counters say whether anything has read the index at all.
 *
 * Every method may return null, and null means "the engine would not say" — never
 * "zero". The difference matters more here than anywhere else in the package: a
 * rule that read an unavailable counter as zero would tell you an index is unused
 * and safe to drop when it is serving every request you have.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
interface IndexStatistics
{
    /**
     * How many times the index has been read since the engine last reset its
     * counters, or null if it cannot be known.
     *
     * The number is cumulative and its window is the *engine's*, not Difflock's. A
     * server restarted an hour ago reports an hour of history, and a rule using this
     * must say so rather than implying the index has been unused forever.
     */
    public function scans(string $table, string $index): ?int;

    /**
     * How long the counters have been accumulating, in days, or null if unknown.
     *
     * Without this a scan count of zero is uninterpretable. Eleven months of zero is
     * evidence; eleven minutes of zero is nothing at all.
     */
    public function observedDays(): ?int;
}
