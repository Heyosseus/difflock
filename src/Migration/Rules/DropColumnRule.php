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
 * `dropColumn()`, and everything that is one under another name — `dropSoftDeletes()`,
 * `dropTimestamps()`, `dropMorphs()`, `dropConstrainedForeignId()`.
 *
 * Always critical, for the same reason as {@see DropTableRule}: the data is gone and
 * `down()` does not bring it back. The rule adds what it can find out about the
 * column from the live schema — that it carries a unique constraint, that an index
 * is built on it — because that is what turns "a column is being dropped" into
 * "something else is about to break".
 */
final class DropColumnRule implements MigrationRule
{
    public function identifier(): string
    {
        return 'drop-column';
    }

    public function analyze(MigrationContext $context): array
    {
        $findings = [];

        foreach ($context->statement->operations as $operation) {
            if (! Blueprint::isDropColumn($operation->method)) {
                continue;
            }

            $columns = Blueprint::columnsOf($operation);

            if ($columns === []) {
                $findings[] = $this->unresolved($context, $operation);

                continue;
            }

            foreach ($columns as $column) {
                $findings[] = $this->column($context, $operation, $column);
            }

            if (! $operation->fullyResolved()) {
                $findings[] = $this->unresolved($context, $operation);
            }
        }

        return $findings;
    }

    private function column(MigrationContext $context, Operation $operation, string $column): MigrationFinding
    {
        $table = $context->tableName() ?? '<unresolved>';

        // Invariant: the same sentence for every dropped column, so the renderer can
        // print it once for the whole group. Everything specific to *this* column —
        // how many rows, what is built on it — goes in the context line.
        $explanation = 'Dropping a column destroys the values in it. A `down()` that adds the column '
            .'back gives you the column and not one row of what was in it.';

        if (str_starts_with($operation->method, 'dropConstrainedForeignId')) {
            $explanation .= ' This form also drops the foreign key constraint on the column, so '
                .'anything relying on it for referential integrity loses it.';
        }

        $facts = [];

        $size = $context->database->describeSize($context->tableName());

        if ($size !== null) {
            $facts[] = $size;
        }

        $dependants = $this->dependants($context->liveTable(), $column);

        if ($dependants !== '') {
            $facts[] = $dependants;
        }

        return $context->finding(
            rule: $this->identifier(),
            risk: RiskLevel::Critical,
            message: 'DROP COLUMN '.$table.'.'.$column,
            explanation: $explanation,
            suggestion: 'Stop reading and writing the column in application code first, deploy that, '
                .'and drop it in a later migration once you are sure nothing needs it.',
            subject: $column,
            subjectType: Subject::Column,
            destructive: true,
            reversible: false,
            operation: $operation,
            context: $facts === [] ? null : implode(' · ', $facts),
        );
    }

    /**
     * What else in the live schema is built on this column.
     *
     * Silent when the database was not reachable or the table is not there: an empty
     * string is "nothing found to say", and the explanation reads correctly without it.
     */
    private function dependants(?Table $table, string $column): string
    {
        if (! $table instanceof Table) {
            return '';
        }

        $indexes = [];

        foreach ($table->indexes as $index) {
            if (in_array($column, $index->columns, true)) {
                $indexes[] = $index;
            }
        }

        $keys = [];

        foreach ($table->foreignKeys as $key) {
            if (in_array($column, $key->columns, true)) {
                $keys[] = $key->name;
            }
        }

        $notes = [];

        if ($indexes !== []) {
            $notes[] = 'covered by '.$this->list(array_map(
                static fn (Index $index): string => $index->name,
                $indexes,
            ));
        }

        if ($keys !== []) {
            $notes[] = 'foreign key '.$this->list($keys).' built on it';
        }

        return implode(', ', $notes);
    }

    private function unresolved(MigrationContext $context, Operation $operation): MigrationFinding
    {
        return $context->finding(
            rule: $this->identifier(),
            risk: RiskLevel::Critical,
            message: 'DROP COLUMN '.($context->tableName() ?? '<unresolved>').' (column named by an expression)',
            explanation: 'A column is being dropped, and its name is built at runtime rather than '
                .'written as a literal, so static analysis cannot say which one. The operation is '
                .'destructive either way.',
            suggestion: 'Read the migration by hand before deploying it. If the name is fixed in '
                .'practice, writing it as a literal lets Difflock check it for you.',
            subjectType: Subject::Column,
            destructive: true,
            reversible: false,
            operation: $operation,
        );
    }

    /**
     * @param  list<string>  $items
     */
    private function list(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
