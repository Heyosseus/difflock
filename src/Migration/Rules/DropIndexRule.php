<?php

declare(strict_types=1);

namespace Difflock\Migration\Rules;

use Difflock\Contracts\MigrationRule;
use Difflock\Migration\Blueprint;
use Difflock\Migration\MigrationContext;
use Difflock\Migration\MigrationFinding;
use Difflock\Migration\Parser\Operation;
use Difflock\Migration\Subject;
use Difflock\Risk\RiskLevel;

/**
 * `dropIndex()`, `dropUnique()`, `dropPrimary()`.
 *
 * The honest position here is narrow, and the rule sticks to it. Difflock has no
 * query workload to consult, so it **cannot** tell you whether dropping an index
 * will make anything slower — anybody claiming otherwise from a migration file alone
 * is guessing. What it can tell you is the difference between the three:
 *
 *   - `dropUnique` and `dropPrimary` remove a *constraint*. Duplicates that the
 *     database was rejecting a moment ago now go in, and putting the constraint back
 *     later means cleaning them out first. That is a correctness change, not a
 *     performance one, and it is reported at high.
 *   - `dropIndex` removes only a performance structure, and is reported at medium —
 *     lower on a table small enough for the difference not to matter.
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

        $risk = match (true) {
            $constraint => RiskLevel::High,
            $context->database->thresholds->isMedium($context->rows()) => RiskLevel::Medium,
            $context->rows() === null => RiskLevel::Medium,
            default => RiskLevel::Low,
        };

        $explanation = $constraint
            ? 'This removes a constraint the database was enforcing. Rows that would have been '
                .'rejected a moment ago are now accepted, and restoring the constraint later means '
                .'finding and resolving whatever got in while it was gone.'
            : 'Difflock has no view of your query workload, so it cannot say whether anything depends '
                .'on this index. What it can say is that queries planned around it will be planned '
                .'differently once it is gone.';

        $explanation .= $this->covered($context, $operation);

        return $context->finding(
            rule: $this->identifier(),
            risk: $risk,
            message: 'DROP '.strtoupper(substr($operation->method, 4)).' '.$table
                .($name === '' ? '' : ' ('.$name.')'),
            explanation: $explanation,
            suggestion: $constraint
                ? 'Confirm nothing relies on the database enforcing uniqueness here — application-level '
                    .'checks are not equivalent under concurrency.'
                : 'Check the index is genuinely unused against a production-shaped workload before '
                    .'dropping it; rebuilding it later on a large table is the expensive direction.',
            subject: $name === '' ? null : $name,
            subjectType: Subject::Index,
            reversible: $context->reversible(),
            operation: $operation,
        );
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
