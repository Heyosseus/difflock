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
 * `$table->string('status')` added to a table that already has rows.
 *
 * This is the migration that passes every review, passes CI against an empty
 * database, and fails in production — because a NOT NULL column with no default has
 * nothing to put in the rows that are already there, and most engines refuse the
 * statement outright.
 *
 * The row count is the whole rule, which is why it is careful with it:
 *
 *   - rows known to be above zero — **high**, this is expected to fail;
 *   - rows known to be zero — **low**, nothing to fill;
 *   - rows unknown — **medium**, said as "may" and with the reason given.
 *
 * It only looks at `Schema::table()`. A column declared inside `Schema::create()` has
 * no existing rows by definition.
 */
final class AddNotNullColumnRule implements MigrationRule
{
    public function identifier(): string
    {
        return 'add-not-null-column';
    }

    public function analyze(MigrationContext $context): array
    {
        if (! $context->statement->isAlter()) {
            return [];
        }

        $findings = [];
        $rows = $context->rows();

        foreach ($context->statement->operations as $operation) {
            if ($operation->hasModifier('change')) {
                // Altering an existing column is {@see ChangeColumnRule}'s question,
                // and answering it here as well would report it twice.
                continue;
            }

            if (! Blueprint::requiresValueForExistingRows($operation)) {
                continue;
            }

            $findings[] = $this->finding($context, $operation, $rows);
        }

        return $findings;
    }

    private function finding(
        MigrationContext $context,
        Operation $operation,
        ?int $rows,
    ): MigrationFinding {
        $column = Blueprint::columnsOf($operation)[0] ?? '<unresolved>';
        $table = $context->tableName() ?? '<unresolved>';

        [$risk, $because] = match (true) {
            $rows === null => [
                RiskLevel::Medium,
                'The number of rows in the table could not be determined, so whether there is anything '
                    .'to fill is unknown.',
            ],
            $rows > 0 => [
                RiskLevel::High,
                'The table holds '.($context->database->describeSize($table) ?? $rows.' rows')
                    .', and every one of them needs a value the migration does not supply. Most '
                    .'engines refuse the statement rather than inventing one.',
            ],
            default => [
                RiskLevel::Low,
                'The table is empty, so there are no existing rows to fill.',
            ],
        };

        return $context->finding(
            rule: $this->identifier(),
            risk: $risk,
            message: 'ADD NOT NULL COLUMN '.$table.'.'.$column.' with no default',
            explanation: 'A new NOT NULL column with no default has nothing to put in rows that '
                .'already exist. '.$because,
            suggestion: 'Add it as `->nullable()` or with a `->default(...)`, backfill the rows, and '
                .'tighten it to NOT NULL in a later migration once every row has a value.',
            subject: $column,
            subjectType: Subject::Column,
            reversible: $context->reversible(),
            operation: $operation,
        );
    }
}
