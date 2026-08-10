<?php

declare(strict_types=1);

namespace Difflock\Database;

use Difflock\Contracts\TableStatistics;

/**
 * Table statistics for a database Difflock could not reach, or a driver that will
 * not answer cheaply.
 *
 * Everything is null, which the rules read as "unknown" rather than as zero. A rule
 * given this object describes what an operation *would* cost on a populated table
 * instead of calling it safe because it saw no rows.
 */
final class NullTableStatistics implements TableStatistics
{
    public function rows(string $table): ?int
    {
        return null;
    }

    public function bytes(string $table): ?int
    {
        return null;
    }

    public function approximate(): bool
    {
        return false;
    }
}
