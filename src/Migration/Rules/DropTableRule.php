<?php

declare(strict_types=1);

namespace Difflock\Migration\Rules;

use Difflock\Contracts\MigrationRule;
use Difflock\Migration\MigrationContext;
use Difflock\Migration\MigrationFinding;
use Difflock\Migration\Subject;
use Difflock\Risk\RiskLevel;

/**
 * `Schema::drop()` and `Schema::dropIfExists()`.
 *
 * Always critical. There is no table size at which dropping one is routine, and
 * there is no `down()` that brings the rows back — recreating the structure is not
 * restoring the data, and a migration that says `Schema::create(...)` in its `down()`
 * gives you an empty table and a false sense of having a way out.
 */
final class DropTableRule implements MigrationRule
{
    public function identifier(): string
    {
        return 'drop-table';
    }

    public function analyze(MigrationContext $context): array
    {
        if ($context->statement->method === 'dropAllTables') {
            return [$this->everything($context)];
        }

        if (! $context->statement->isDrop()) {
            return [];
        }

        $table = $context->tableName();
        $size = $context->database->describeSize($table);

        $explanation = 'Dropping a table destroys every row in it. A `down()` that recreates the '
            .'table restores the structure and none of the data.';

        if ($size !== null) {
            $explanation .= ' This table currently holds '.$size.'.';
        }

        if (! $context->database->available) {
            $explanation .= ' The database could not be reached, so the size of the table is unknown.';
        } elseif ($table !== null && ! $context->database->hasTable($table)) {
            $explanation .= ' The table does not exist on the inspected database, so this drop may '
                .'already have run there, or may target a database this one is not.';
        }

        return [$context->finding(
            rule: $this->identifier(),
            risk: RiskLevel::Critical,
            message: 'DROP TABLE '.($table ?? '<unresolved>'),
            explanation: $explanation,
            suggestion: 'Ship the drop as its own deploy, after the application has stopped reading the '
                .'table, and take a backup you have restored from at least once.',
            subject: $table,
            subjectType: Subject::Table,
            destructive: true,
            reversible: false,
        )];
    }

    private function everything(MigrationContext $context): MigrationFinding
    {
        return $context->finding(
            rule: $this->identifier(),
            risk: RiskLevel::Critical,
            message: 'DROP ALL TABLES',
            explanation: 'This removes every table on the connection. It is a reset, not a migration, '
                .'and nothing about it is reversible.',
            suggestion: 'If this belongs to a test or a local reset routine, keep it out of the '
                .'migration path — or add its migration to `ignore.migrations` deliberately.',
            destructive: true,
            reversible: false,
        );
    }
}
