<?php

declare(strict_types=1);

namespace Difflock\Migration;

use Difflock\Migration\Parser\ParsedMigration;
use Difflock\Risk\RiskLevel;
use Difflock\Risk\RiskSummary;

/**
 * Everything one run of the analyzer found.
 *
 * The warnings are as important as the findings. A report over four migrations, one
 * of which builds its schema in a loop, is not a report over four migrations — and
 * a tool that printed "4 analysed, nothing found" without saying so would be
 * lying by omission at exactly the moment it mattered.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class MigrationReport
{
    /**
     * @param  list<ParsedMigration>  $migrations
     * @param  list<MigrationFinding>  $findings  Ordered most serious first.
     * @param  bool  $databaseAvailable  False when the analysis ran without a reachable database,
     *                                   so every size-dependent judgement was made blind.
     * @param  list<MigrationFinding>  $accepted  Findings suppressed by the accepted-findings file.
     *                                            Counted and reported, never silently dropped.
     */
    public function __construct(
        public array $migrations = [],
        public array $findings = [],
        public bool $databaseAvailable = true,
        public array $accepted = [],
    ) {}

    /**
     * Everything the rules found, accepted or not — what `--accept` records.
     *
     * @return list<MigrationFinding>
     */
    public function allFindings(): array
    {
        return [...$this->findings, ...$this->accepted];
    }

    public function summary(): RiskSummary
    {
        return RiskSummary::of($this->findings);
    }

    public function highestRisk(): RiskLevel
    {
        return $this->summary()->highest;
    }

    /** Whether anything found is at or above the bar. */
    public function fails(RiskLevel $threshold): bool
    {
        return $this->summary()->crosses($threshold);
    }

    /**
     * Everything the parser could not fully read, across every migration analysed.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];

        foreach ($this->migrations as $migration) {
            foreach ($migration->warnings as $warning) {
                $warnings[] = $warning;
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @return list<MigrationFinding>
     */
    public function findingsFor(string $migration): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (MigrationFinding $finding): bool => $finding->migration === $migration,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $summary = $this->summary();

        return [
            'migrations' => array_map(
                static fn (ParsedMigration $migration): string => $migration->name,
                $this->migrations,
            ),
            'analyzed' => count($this->migrations),
            'risk' => $summary->highest->value,
            'counts' => $summary->counts,
            'database_available' => $this->databaseAvailable,
            'accepted' => count($this->accepted),
            'warnings' => $this->warnings(),
            'findings' => array_map(
                static fn (MigrationFinding $finding): array => $finding->toArray(),
                $this->findings,
            ),
        ];
    }
}
