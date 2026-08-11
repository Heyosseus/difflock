<?php

declare(strict_types=1);

namespace Difflock\Mcp\Tools;

use Difflock\Checkup;
use Difflock\Mcp\Tool;
use Difflock\Risk\RiskLevel;

/**
 * "Is this database still what we agreed it was?"
 *
 * Drift against the committed baseline, plus the state of the pending migrations —
 * the whole verdict, the same one `difflock:check` gives, in a form an agent can act
 * on.
 *
 * Worth calling before touching the schema at all: a database that has already
 * drifted is one where every assumption in the migration about to be written may
 * already be wrong.
 */
final readonly class SchemaDrift implements Tool
{
    public function __construct(private Checkup $checkup) {}

    public function name(): string
    {
        return 'difflock_schema_drift';
    }

    public function description(): string
    {
        return 'Report whether the live database still matches the committed schema baseline, and '
            .'what the pending migrations would do. Call this before starting schema work: a database '
            .'that has already drifted invalidates assumptions a new migration would make. If '
            .'baseline_recorded is false, drift was not checked because nobody has recorded a '
            .'baseline — that is not the same as no drift.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }

    public function handle(array $arguments): array
    {
        $result = $this->checkup->run(RiskLevel::Critical);

        return [
            'passed' => ! $result->failed(),
            'baseline_recorded' => $result->baselineRecorded,
            'baseline_error' => $result->baselineError,
            'drifted' => $result->drifted(),
            'drift' => $result->drift?->toArray(),
            'pending_migrations' => $result->report->toArray(),
        ];
    }
}
