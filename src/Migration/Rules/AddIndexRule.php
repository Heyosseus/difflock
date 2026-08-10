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
 * `$table->index('customer_id')` and `$table->unique('email')` on a table that
 * already exists.
 *
 * Two separate concerns live here and they are reported as such.
 *
 * **Building the index costs something.** How much, and whether anything is locked
 * while it happens, depends entirely on the engine and its version — PostgreSQL has
 * `CREATE INDEX CONCURRENTLY`, MySQL 8 has online DDL with its own exceptions, older
 * versions have neither, and Laravel's schema builder emits none of them. Difflock
 * therefore scales the risk by table size and says *may*, because that is the
 * strongest honest claim available without knowing the server.
 *
 * **A unique index can fail outright.** If the existing rows contain duplicates, the
 * statement is rejected and the migration stops. That is not a performance question
 * and it does not depend on the engine, so a unique index on a populated table is
 * reported at high whatever its size.
 *
 * Indexes declared inside `Schema::create()` are ignored: the table is empty.
 */
final class AddIndexRule implements MigrationRule
{
    public function identifier(): string
    {
        return 'add-index';
    }

    public function analyze(MigrationContext $context): array
    {
        if (! $context->statement->isAlter()) {
            return [];
        }

        $findings = [];

        foreach ($context->statement->operations as $operation) {
            if (! Blueprint::isIndex($operation->method)) {
                continue;
            }

            $findings[] = $this->finding($context, $operation);
        }

        return $findings;
    }

    private function finding(MigrationContext $context, Operation $operation): MigrationFinding
    {
        $table = $context->tableName() ?? '<unresolved>';
        $columns = $operation->columns();
        $rows = $context->rows();
        $unique = $operation->method === 'unique';
        $size = $context->database->describeSize($table);

        $risk = match (true) {
            $unique && ($rows === null || $rows > 0) => RiskLevel::High,
            $context->database->thresholds->isLarge($rows) => RiskLevel::High,
            $context->database->thresholds->isMedium($rows) => RiskLevel::Medium,
            default => RiskLevel::Low,
        };

        $explanation = 'Building an index on an existing table reads every row in it. Whether that '
            .'takes a lock, and for how long, depends on the database engine and version — Difflock '
            .'does not know which applies here, so it judges by size alone.';

        $explanation .= $size === null
            ? ' The size of the table could not be determined.'
            : ' The table holds '.$size.'.';

        if ($unique) {
            $explanation .= ' A unique index also fails outright if the existing rows contain '
                .'duplicates, which stops the migration partway through.';
        }

        return $context->finding(
            rule: $this->identifier(),
            risk: $risk,
            message: 'ADD '.($unique ? 'UNIQUE ' : '').'INDEX '.$table
                .($columns === [] ? '' : ' ('.implode(', ', $columns).')'),
            explanation: $explanation,
            suggestion: $this->suggestion($unique, $risk),
            subject: $operation->stringArgument(1) ?? ($columns === [] ? null : implode(', ', $columns)),
            subjectType: Subject::Index,
            reversible: $context->reversible(),
            operation: $operation,
        );
    }

    private function suggestion(bool $unique, RiskLevel $risk): ?string
    {
        if ($unique) {
            return 'Check for duplicates before deploying, and consider building the index outside the '
                .'migration with whatever concurrent form your engine offers.';
        }

        return $risk->atLeast(RiskLevel::Medium)
            ? 'On a table this size, consider creating the index outside the deploy window using your '
                .'engine\'s concurrent or online form, and letting the migration only record that it exists.'
            : null;
    }
}
