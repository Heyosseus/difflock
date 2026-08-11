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
use Difflock\Schema\Index;
use Difflock\Schema\Table;

/**
 * A foreign key with no index on the referencing column.
 *
 * **PostgreSQL indexes the key a foreign key points at, and creates nothing for the
 * column doing the pointing. MySQL and MariaDB create one automatically.** That
 * single difference is the most common performance bug in a Laravel application on
 * PostgreSQL, and it is invisible in the migration that causes it:
 *
 *     $table->foreignId('customer_id')->constrained();
 *
 * On MySQL that is complete. On PostgreSQL every `$customer->delete()` from then on
 * sequentially scans the child table — while holding a lock on the parent row — to
 * check whether any child still references it. Joins from parent to child scan too.
 * The cost never appears where anybody looks for it, because the slow statement is
 * the `DELETE`, not the query anyone wrote.
 *
 * The rule is engine-aware, which is what makes it worth trusting. Against MySQL or
 * MariaDB it says nothing at all, because there is nothing to say. Where the engine
 * cannot be determined it reports at low and names the engines it applies to,
 * rather than warning a MySQL team about a problem they do not have.
 */
final class UnindexedForeignKeyRule implements MigrationRule
{
    /**
     * Engines that create an index for the referencing column themselves.
     *
     * @var list<string>
     */
    private const array INDEXES_AUTOMATICALLY = ['mysql', 'mariadb'];

    public function identifier(): string
    {
        return 'unindexed-foreign-key';
    }

    public function analyze(MigrationContext $context): array
    {
        $driver = $context->database->driver();

        if ($driver !== null && in_array($driver, self::INDEXES_AUTOMATICALLY, true)) {
            return [];
        }

        $findings = [];

        foreach ($context->statement->operations as $operation) {
            if (! $this->addsForeignKey($operation)) {
                continue;
            }

            $column = $operation->stringArgument(0);
            if ($column === null) {
                continue;
            }
            if ($this->indexed($context, $column)) {
                continue;
            }

            $findings[] = $this->finding($context, $operation, $column, $driver);
        }

        return $findings;
    }

    private function addsForeignKey(Operation $operation): bool
    {
        return $operation->method === 'foreign' || $operation->hasModifier('constrained');
    }

    /**
     * Whether anything already covers the column — an index declared in this same
     * closure, or one that exists on the live table.
     *
     * A composite index counts when the column comes *first*: an index on
     * `(customer_id, created_at)` serves a lookup by `customer_id`, while one on
     * `(created_at, customer_id)` does not.
     */
    private function indexed(MigrationContext $context, string $column): bool
    {
        foreach ($context->statement->operations as $operation) {
            if (! Blueprint::isIndex($operation->method)) {
                continue;
            }

            $columns = $operation->columns();

            if (($columns[0] ?? null) === $column) {
                return true;
            }
        }

        $table = $context->liveTable();

        if (! $table instanceof Table) {
            return false;
        }

        foreach ($table->indexes as $index) {
            if (($index->columns[0] ?? null) === $column) {
                return true;
            }
        }

        // A column that is itself the primary key needs nothing more.
        return $table->indexOn([$column]) instanceof Index;
    }

    private function finding(
        MigrationContext $context,
        Operation $operation,
        string $column,
        ?string $driver,
    ): MigrationFinding {
        $table = $context->tableName() ?? '<unresolved>';
        $known = $driver !== null;

        $explanation = $known
            ? $this->engine($driver).' creates no index for the column a foreign key points *from*, so '
                .'nothing here covers `'.$column.'`. Deleting or updating a row in the parent table will '
                .'scan this table to check for children, and joins from the parent will scan it too.'
            : 'The database engine could not be determined. On PostgreSQL and SQLite no index is '
                .'created for the column a foreign key points from; on MySQL and MariaDB one is created '
                .'automatically and this finding does not apply.';

        return $context->finding(
            rule: $this->identifier(),
            risk: $this->risk($context, $known),
            message: 'UNINDEXED FOREIGN KEY '.$table.'.'.$column,
            explanation: $explanation,
            suggestion: 'Add `$table->index(\''.$column.'\');` alongside the constraint. It costs one '
                .'line now and is an index build on a populated table later.',
            subject: $column,
            subjectType: Subject::Column,
            reversible: $context->reversible(),
            operation: $operation,
            context: $context->database->describeSize($context->tableName()),
        );
    }

    /** The engine's own name, so the explanation never tells a SQLite user about PostgreSQL. */
    private function engine(?string $driver): string
    {
        return match ($driver) {
            'pgsql' => 'PostgreSQL',
            'sqlite' => 'SQLite',
            'sqlsrv' => 'SQL Server',
            default => 'This database engine',
        };
    }

    private function risk(MigrationContext $context, bool $known): RiskLevel
    {
        if (! $known) {
            return RiskLevel::Low;
        }

        // The scan is proportional to the child table, so the bigger it already is,
        // the sooner this is felt.
        return $context->database->thresholds->isMedium($context->rows())
            ? RiskLevel::High
            : RiskLevel::Medium;
    }
}
