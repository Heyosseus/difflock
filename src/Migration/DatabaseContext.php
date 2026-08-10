<?php

declare(strict_types=1);

namespace Difflock\Migration;

use Difflock\Contracts\TableStatistics;
use Difflock\Schema\DatabaseSchema;
use Difflock\Schema\Table;

/**
 * What the rules know about the database the migration is heading for.
 *
 * This is the difference between a linter and a safety tool. `dropColumn('status')`
 * reads the same in every repository; whether it is a nuisance or an incident
 * depends on whether the table has eleven rows or eleven million, and on whether the
 * column is the one a unique constraint is built on. Rules ask this object, and it
 * answers null wherever the database could not be reached or would not say — so a
 * rule that needs a row count and cannot have one says "unknown" rather than
 * assuming zero.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class DatabaseContext
{
    /**
     * @param  bool  $available  False when Difflock could not reach the database at all, so
     *                           every rule is working from source alone. Reported, never hidden.
     */
    public function __construct(
        public DatabaseSchema $schema,
        public TableStatistics $statistics,
        public Thresholds $thresholds = new Thresholds,
        public string $environment = 'unknown',
        public ?string $version = null,
        public bool $available = true,
    ) {}

    public function driver(): ?string
    {
        return $this->schema->driver;
    }

    /** The table as it exists right now, or null if it does not exist yet. */
    public function table(?string $name): ?Table
    {
        return $name === null ? null : $this->schema->table($name);
    }

    public function hasTable(?string $name): bool
    {
        return $name !== null && $this->schema->hasTable($name);
    }

    /** Roughly how many rows the table holds, or null when that cannot be known. */
    public function rows(?string $name): ?int
    {
        return $name === null || ! $this->available ? null : $this->statistics->rows($name);
    }

    public function bytes(?string $name): ?int
    {
        return $name === null || ! $this->available ? null : $this->statistics->bytes($name);
    }

    /** Whether the environment Difflock is running in is the production one. */
    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }

    /**
     * A phrase describing the table's size for an explanation, or null when the size
     * is unknown and the explanation should not mention one.
     */
    public function describeSize(?string $name): ?string
    {
        $rows = $this->rows($name);

        if ($rows === null) {
            return null;
        }

        $described = Thresholds::format($rows).' row'.($rows === 1 ? '' : 's');

        return $this->statistics->approximate() ? 'roughly '.$described : $described;
    }
}
