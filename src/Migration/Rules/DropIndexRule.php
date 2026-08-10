<?php

declare(strict_types=1);

namespace Difflock\Migration\Rules;

use Difflock\Contracts\MigrationRule;
use Difflock\Migration\Blueprint;
use Difflock\Migration\MigrationContext;
use Difflock\Migration\MigrationFinding;
use Difflock\Migration\Parser\Operation;
use Difflock\Migration\Subject;
use Difflock\Migration\Thresholds;
use Difflock\Risk\RiskLevel;

/**
 * `dropIndex()`, `dropUnique()`, `dropPrimary()`.
 *
 * There are two separate questions here and the rule keeps them apart.
 *
 * `dropUnique` and `dropPrimary` remove a **constraint**. Duplicates the database
 * was rejecting a moment ago now go in, and restoring the constraint later means
 * finding and resolving whatever got in meanwhile. That is a correctness change, it
 * does not depend on anybody's query workload, and it is reported at high.
 *
 * `dropIndex` removes only a performance structure, and whether that matters depends
 * on whether anything reads it — which Difflock used to be unable to say. Now it
 * asks the engine, which has been counting all along: `pg_stat_user_indexes` on
 * PostgreSQL, `performance_schema` on MySQL. So the finding becomes evidence:
 *
 *     0 reads in 274 days   →  low, and says so
 *     2.1M reads            →  high, and says so
 *
 * The counters carry their own caveats and the rule repeats them rather than
 * rounding them off. They are cumulative since the engine last reset them, so zero
 * reads on a server restarted this morning means nothing; the window is quoted with
 * the number. They count reads on *this* instance, so a replica serving the traffic
 * is invisible from here. And where the engine will not answer at all, the rule
 * falls back to what it said before: it does not know, and says that too.
 */
final class DropIndexRule implements MigrationRule
{
    public function identifier(): string
    {
        return 'drop-index';
    }

    public function analyze(MigrationContext $context): array
    {
        $findings = [];

        foreach ($context->statement->operations as $operation) {
            if (! Blueprint::isDropIndex($operation->method)) {
                continue;
            }

            $findings[] = $this->finding($context, $operation);
        }

        return $findings;
    }

    private function finding(MigrationContext $context, Operation $operation): MigrationFinding
    {
        $table = $context->tableName() ?? '<unresolved>';
        $name = $operation->stringArgument(0) ?? implode(', ', $operation->columns());
        $constraint = $operation->method === 'dropUnique' || $operation->method === 'dropPrimary';

        $scans = $name === '' ? null : $context->database->indexScans($context->tableName(), $name);

        $risk = match (true) {
            $constraint => RiskLevel::High,
            $scans !== null && $scans === 0 => RiskLevel::Low,
            $scans !== null && $scans > 0 => RiskLevel::High,
            $context->database->thresholds->isMedium($context->rows()) => RiskLevel::Medium,
            $context->rows() === null => RiskLevel::Medium,
            default => RiskLevel::Low,
        };

        $explanation = $constraint
            ? 'This removes a constraint the database was enforcing. Rows that would have been '
                .'rejected a moment ago are now accepted, and restoring the constraint later means '
                .'finding and resolving whatever got in while it was gone.'
            : $this->usage($context, $scans);

        $explanation .= $this->covered($context, $operation);

        return $context->finding(
            rule: $this->identifier(),
            risk: $risk,
            message: 'DROP '.strtoupper(substr($operation->method, 4)).' '.$table
                .($name === '' ? '' : ' ('.$name.')'),
            explanation: $explanation,
            suggestion: $this->suggestion($constraint, $scans),
            subject: $name === '' ? null : $name,
            subjectType: Subject::Index,
            reversible: $context->reversible(),
            operation: $operation,
        );
    }

    /**
     * What the engine's own counters say about this index.
     *
     * Every branch quotes the window alongside the number, because a scan count
     * without one is uninterpretable — zero reads since a restart an hour ago is not
     * evidence of anything.
     */
    private function usage(MigrationContext $context, ?int $scans): string
    {
        if ($scans === null) {
            return 'The engine would not say how often this index has been read, so Difflock cannot '
                .'tell you whether anything depends on it. What it can say is that queries planned '
                .'around it will be planned differently once it is gone.';
        }

        $window = $context->database->indexObservedDays();
        $over = $window === null
            ? 'since the engine last reset its statistics'
            : 'over the '.$window.' day'.($window === 1 ? '' : 's').' since the engine last reset its statistics';

        if ($scans === 0) {
            return 'The engine reports this index has been read '.($window === null ? 'no times ' : 'no times ')
                .$over.'. That is the strongest evidence available that nothing needs it — with two '
                .'caveats: the counters are per instance, so a replica serving reads is invisible '
                .'from here, and a short window since a restart proves nothing.';
        }

        return 'The engine reports this index has been read '.Thresholds::format($scans).' time'
            .($scans === 1 ? '' : 's').' '.$over.'. Something is using it, and those queries will be '
            .'planned differently once it is gone.';
    }

    private function suggestion(bool $constraint, ?int $scans): string
    {
        if ($constraint) {
            return 'Confirm nothing relies on the database enforcing uniqueness here — application-level '
                .'checks are not equivalent under concurrency.';
        }

        if ($scans !== null && $scans > 0) {
            return 'Find what reads it before dropping it. If the goal is to replace it with a better '
                .'index, create the replacement first and drop this one afterwards.';
        }

        if ($scans === 0) {
            return 'Check the window is long enough to be meaningful, and that no replica or reporting '
                .'database relies on it, then this looks safe to drop.';
        }

        return 'Check the index is genuinely unused against a production-shaped workload before '
            .'dropping it; rebuilding it later on a large table is the expensive direction.';
    }

    /** What the index being dropped actually covers, when the live schema can say. */
    private function covered(MigrationContext $context, Operation $operation): string
    {
        $name = $operation->stringArgument(0);
        $index = $name === null ? null : $context->liveTable()?->index($name);

        if (! $index instanceof \Difflock\Schema\Index) {
            return '';
        }

        return ' It currently covers ('.implode(', ', $index->columns).').';
    }
}
