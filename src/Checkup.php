<?php

declare(strict_types=1);

namespace Difflock;

use Difflock\Contracts\MigrationAnalyzer;
use Difflock\Contracts\SchemaDiffer;
use Difflock\Contracts\SchemaInspector;
use Difflock\Diff\SchemaDiff;
use Difflock\Migration\MigrationScope;
use Difflock\Risk\RiskLevel;
use Difflock\Schema\Baseline;
use Throwable;

/**
 * Both halves of a Difflock run: has the schema drifted, and is what is about to run
 * safe.
 *
 * Shared by `difflock` and `difflock:check` so the two can never disagree about
 * whether a run passed. Which of them is prettier is a rendering decision; whether
 * the build should go red is this.
 */
final readonly class Checkup
{
    public function __construct(
        private SchemaInspector $inspector,
        private SchemaDiffer $differ,
        private MigrationAnalyzer $analyzer,
        private Baseline $baseline,
    ) {}

    public function run(RiskLevel $threshold, ?string $connection = null): CheckupResult
    {
        [$drift, $error] = $this->drift($connection);

        $report = $this->analyzer->analyze(MigrationScope::Pending);

        return new CheckupResult(
            drift: $drift,
            report: $report,
            threshold: $threshold,
            baselineRecorded: $this->baseline->exists(),
            baselineError: $error,
        );
    }

    /**
     * The drift, or null when there is no baseline to measure it against.
     *
     * A baseline that exists but cannot be read is a different thing entirely, and it
     * is carried back as an error rather than folded into "no baseline": a check that
     * treated an unreadable snapshot as an absent one would go green on a corrupted
     * file.
     *
     * @return array{0: SchemaDiff|null, 1: string|null}
     */
    private function drift(?string $connection): array
    {
        if (! $this->baseline->exists()) {
            return [null, null];
        }

        try {
            return [$this->differ->diff($this->baseline->read(), $this->inspector->inspect($connection)), null];
        } catch (Throwable $exception) {
            return [null, $exception->getMessage()];
        }
    }
}
