<?php

declare(strict_types=1);

namespace Difflock\Protection;

use Difflock\Migration\MigrationReport;
use Difflock\Risk\RiskLevel;

/**
 * When the guard should refuse to let migrations run.
 *
 * Separate from the guard itself so that "what counts as too risky" is a decision
 * the application makes in configuration, and "what to do about it" is a decision
 * Difflock makes in code.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class ProtectionPolicy
{
    public function __construct(
        public bool $enabled = true,
        public RiskLevel $blockOn = RiskLevel::Critical,
    ) {}

    public function blocks(MigrationReport $report): bool
    {
        return $this->enabled && $report->fails($this->blockOn);
    }

    /** The same policy with protection switched off, for an explicit `--force`. */
    public function disabled(): self
    {
        return new self(false, $this->blockOn);
    }
}
