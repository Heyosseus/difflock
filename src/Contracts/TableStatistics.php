<?php

declare(strict_types=1);

namespace Difflock\Contracts;

/**
 * How big a table is, as cheaply as the driver can be made to say.
 *
 * Every method may return null, and null is a real answer: "this driver will not
 * tell me" is not the same as "this table is empty", and a rule that confused the
 * two would call a migration against an eight-million-row table safe. Difflock never
 * invents a number to fill the gap.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
interface TableStatistics
{
    /**
     * Roughly how many rows the table holds, or null if it cannot be known cheaply.
     *
     * Implementations are expected to read database metadata rather than count rows:
     * `SELECT COUNT(*)` on a large production table is exactly the kind of expensive
     * surprise a safety tool should not spring on you.
     */
    public function rows(string $table): ?int;

    /** Roughly how many bytes the table occupies, or null if it cannot be known. */
    public function bytes(string $table): ?int;

    /** Whether these figures are estimates from database metadata rather than exact. */
    public function approximate(): bool;
}
