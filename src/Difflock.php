<?php

declare(strict_types=1);

namespace Difflock;

use Difflock\Contracts\MigrationAnalyzer;
use Difflock\Contracts\MigrationRule;
use Difflock\Contracts\SchemaDiffer;
use Difflock\Contracts\SchemaInspector;
use Difflock\Diff\SchemaDiff;
use Difflock\Migration\MigrationFinding;
use Difflock\Migration\MigrationReport;
use Difflock\Migration\MigrationScope;
use Difflock\Protection\GuardDecision;
use Difflock\Protection\MigrationGuard;
use Difflock\Schema\Baseline;
use Difflock\Schema\DatabaseSchema;

/**
 * The package's front door.
 *
 * Everything reachable here is reachable through the container too — `Difflock` is a
 * convenience over the contracts, never a replacement for them, and nothing in the
 * package depends on it. Inject {@see MigrationAnalyzer} or {@see SchemaDiffer} if
 * you would rather, and none of this changes.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class Difflock
{
    public function __construct(
        private SchemaInspector $inspector,
        private SchemaDiffer $differ,
        private MigrationAnalyzer $analyzer,
        private MigrationGuard $guard,
        private Baseline $baseline,
        private RuleRegistry $rules,
    ) {}

    /** The live schema of a connection. */
    public function inspect(?string $connection = null): DatabaseSchema
    {
        return $this->inspector->inspect($connection);
    }

    /**
     * Compare two connections' schemas.
     *
     * Both sides are inspected live. To compare against a recorded schema instead,
     * use {@see self::drift()}.
     */
    public function diff(?string $from = null, ?string $to = null): SchemaDiff
    {
        return $this->differ->diff($this->inspect($from), $this->inspect($to));
    }

    /**
     * Compare the live schema against the recorded baseline.
     *
     * @throws Exceptions\MissingBaseline When no baseline has been recorded.
     */
    public function drift(?string $connection = null): SchemaDiff
    {
        return $this->differ->diff($this->baseline->read(), $this->inspect($connection));
    }

    /** Record the live schema as the baseline that future drift is measured against. */
    public function record(?string $connection = null): DatabaseSchema
    {
        $schema = $this->inspect($connection);

        $this->baseline->write($schema);

        return $schema;
    }

    public function baseline(): Baseline
    {
        return $this->baseline;
    }

    /** Analyse migrations and return the whole report. */
    public function analyze(MigrationScope $scope = MigrationScope::Pending): MigrationReport
    {
        return $this->analyzer->analyze($scope);
    }

    /**
     * Analyse migrations and return just the findings.
     *
     * @return list<MigrationFinding>
     */
    public function lint(MigrationScope $scope = MigrationScope::Pending): array
    {
        return $this->analyze($scope)->findings;
    }

    /** Whether the pending migrations should be allowed to run. */
    public function guard(): GuardDecision
    {
        return $this->guard->inspect();
    }

    /**
     * Register a rule of your own.
     *
     * Call it from a service provider's `boot()`; the analyzer reads the registry
     * when it runs, so registration order does not matter.
     *
     *     Difflock::rule(NoTriggersRule::class);
     *
     * @param  class-string<MigrationRule>|MigrationRule  $rule
     */
    public function rule(string|MigrationRule $rule): self
    {
        $this->rules->add($rule);

        return $this;
    }
}
