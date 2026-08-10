<?php

declare(strict_types=1);

namespace Difflock\Risk;

use Difflock\Migration\MigrationFinding;

/**
 * How many findings landed on each risk level, and the worst one among them.
 *
 * Derived from the findings rather than accumulated alongside them, so the summary
 * printed at the bottom of a report can never disagree with the list above it.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class RiskSummary
{
    /**
     * @param  array<string, int>  $counts  Keyed by {@see RiskLevel::value}, every level present.
     */
    private function __construct(
        public array $counts,
        public RiskLevel $highest,
        public int $total,
    ) {}

    /**
     * @param  list<MigrationFinding>  $findings
     */
    public static function of(array $findings): self
    {
        $counts = [
            RiskLevel::Safe->value => 0,
            RiskLevel::Low->value => 0,
            RiskLevel::Medium->value => 0,
            RiskLevel::High->value => 0,
            RiskLevel::Critical->value => 0,
        ];

        $highest = RiskLevel::Safe;

        foreach ($findings as $finding) {
            $counts[$finding->risk->value]++;
            $highest = $highest->max($finding->risk);
        }

        return new self($counts, $highest, count($findings));
    }

    /** How many findings landed on the given level. */
    public function count(RiskLevel $level): int
    {
        return $this->counts[$level->value] ?? 0;
    }

    /**
     * Whether anything here is at or above the bar.
     *
     * An empty set of findings never crosses a bar, including the safe one: a
     * migration nothing objected to should not fail a build because somebody set
     * the threshold to its floor.
     */
    public function crosses(RiskLevel $threshold): bool
    {
        return $this->total > 0 && $this->highest->atLeast($threshold);
    }
}
