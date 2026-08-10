<?php

declare(strict_types=1);

namespace Difflock;

use Difflock\Diff\SchemaDiff;
use Difflock\Migration\MigrationReport;
use Difflock\Risk\RiskLevel;

/**
 * What a full Difflock run concluded.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class CheckupResult
{
    /**
     * @param  SchemaDiff|null  $drift  Null when no baseline was recorded, or it could not be read.
     * @param  string|null  $baselineError  Why the recorded baseline could not be used, if it could not.
     */
    public function __construct(
        public ?SchemaDiff $drift,
        public MigrationReport $report,
        public RiskLevel $threshold,
        public bool $baselineRecorded = false,
        public ?string $baselineError = null,
    ) {}

    /** Whether the schema differs from the baseline. False when drift was not checked. */
    public function drifted(): bool
    {
        return $this->drift instanceof SchemaDiff && ! $this->drift->isEmpty();
    }

    /**
     * Whether the run should fail the build.
     *
     * Three separate things fail it, and each of them is a real problem: the schema
     * has drifted from what was recorded, a migration finding is at or above the
     * threshold, or the baseline exists and could not be read. The last one matters
     * most — a check that cannot read its own baseline has not passed, it has failed
     * to run.
     */
    public function failed(): bool
    {
        if ($this->baselineError !== null) {
            return true;
        }
        if ($this->drifted()) {
            return true;
        }

        return $this->report->fails($this->threshold);
    }
}
