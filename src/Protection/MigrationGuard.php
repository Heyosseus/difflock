<?php

declare(strict_types=1);

namespace Difflock\Protection;

use Difflock\Contracts\MigrationAnalyzer;
use Difflock\Migration\MigrationScope;

/**
 * Analyses the migrations that are about to run and says whether they should.
 *
 * The guard is explicit by design. It is reached through `php artisan
 * difflock:migrate`, never by wrapping or replacing `php artisan migrate`, and it
 * hooks nothing globally. Two reasons, and both of them matter more than the
 * convenience of automatic interception:
 *
 *   - a package that silently changes what `migrate` does is a package that can
 *     break a deploy pipeline nobody asked it to be part of;
 *   - a guard you have to opt into is a guard whose absence is visible. One that
 *     hooks itself in is one that fails open the day the hook stops firing, and
 *     nobody notices for a year.
 *
 * The guard never executes a migration itself. It analyses, it decides, and the
 * command it answers to is the thing that then calls Laravel's own `migrate`.
 */
final readonly class MigrationGuard
{
    public function __construct(
        private MigrationAnalyzer $analyzer,
        private ProtectionPolicy $policy,
    ) {}

    /**
     * @param  list<string>  $paths  Analyse only these directories.
     */
    public function inspect(array $paths = [], ?ProtectionPolicy $policy = null): GuardDecision
    {
        $policy ??= $this->policy;

        $report = $this->analyzer->analyze(MigrationScope::Pending, $paths);

        return new GuardDecision(
            report: $report,
            blocked: $policy->blocks($report),
            threshold: $policy->blockOn,
            enforced: $policy->enabled,
        );
    }

    public function policy(): ProtectionPolicy
    {
        return $this->policy;
    }
}
