<?php

declare(strict_types=1);

namespace Difflock\Protection;

use Difflock\Migration\MigrationReport;
use Difflock\Risk\RiskLevel;

/**
 * What the guard concluded, and why.
 *
 * A decision, not an action. Nothing here runs or refuses anything — the caller
 * reads it and decides. That separation is what keeps the guard testable without a
 * database and keeps Difflock's promise that installing it changes nothing about
 * when your migrations run.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class GuardDecision
{
    public function __construct(
        public MigrationReport $report,
        public bool $blocked,
        public RiskLevel $threshold,
        public bool $enforced = true,
    ) {}

    public function allowed(): bool
    {
        return ! $this->blocked;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'blocked' => $this->blocked,
            'threshold' => $this->threshold->value,
            'protection_enforced' => $this->enforced,
        ] + $this->report->toArray();
    }
}
