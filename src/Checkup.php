<?php

declare(strict_types=1);

namespace Difflock;

use Difflock\Contracts\MigrationAnalyzer;
use Difflock\Contracts\SchemaDiffer;
use Difflock\Contracts\SchemaInspector;
use Difflock\Database\DatabaseContextFactory;
use Difflock\Diff\SchemaDiff;
use Difflock\Migration\MigrationScope;
use Difflock\Risk\RiskLevel;
use Difflock\Schema\Baseline;
use Difflock\Schema\DatabaseSchema;
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
        private ?DatabaseContextFactory $contexts = null,
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
            return [$this->differ->diff($this->baseline->read(), $this->live($connection)), null];
        } catch (Throwable $exception) {
            return [null, $exception->getMessage()];
        }
    }

    /**
     * The live schema, reusing the one the rules are about to be given.
     *
     * A check reads the schema for drift and the analyzer reads it again to give the
     * rules their context. Reading it twice is not free — on a 99-table PostgreSQL
     * database it measured 598 queries and 3.7 seconds, half of it repeated — so the
     * two share one reading, held by the context factory for exactly the length of
     * this run and no longer.
     *
     * An explicit `--connection` is the one case that cannot share: the factory is
     * built around the configured connection, and inspecting a different one is a
     * different question. That path reads for itself.
     */
    private function live(?string $connection): DatabaseSchema
    {
        if ($connection !== null || ! $this->contexts instanceof DatabaseContextFactory) {
            return $this->inspector->inspect($connection);
        }

        return $this->contexts->make()->schema;
    }
}
