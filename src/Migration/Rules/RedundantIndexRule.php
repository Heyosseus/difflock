<?php

declare(strict_types=1);

namespace Difflock\Migration\Rules;

use Difflock\Contracts\MigrationRule;
use Difflock\Migration\MigrationContext;
use Difflock\Migration\MigrationFinding;
use Difflock\Migration\Parser\Operation;
use Difflock\Migration\Subject;
use Difflock\Risk\RiskLevel;
use Difflock\Schema\Index;
use Difflock\Schema\Table;

/**
 * An index the table already has, in everything but name.
 *
 * A B-tree on `(status, created_at)` already serves every lookup that an index on
 * `(status)` alone would serve, because the engine can use any leading subset of a
 * composite index. Adding the shorter one buys nothing and costs on every insert,
 * update and delete forever, plus the disk to hold it.
 *
 * It happens easily and invisibly. Somebody adds `$table->index('status')` in one
 * migration; a year later somebody else adds `$table->index(['status', 'type'])` for
 * a new query, and now the first is dead weight nobody will ever think to look for.
 *
 * ## Where the rule stops
 *
 * Only the *leading prefix* case is reported, because only that one is certain. An
 * index on `(created_at, status)` does **not** make `(status)` redundant, and the
 * rule does not pretend otherwise. Partial indexes, expression indexes and differing
 * access methods are left alone entirely — a GIN index and a B-tree on the same
 * column are not substitutes, and Difflock cannot always tell them apart from the
 * schema alone.
 *
 * Reported at low. Nothing breaks; it is waste, and waste that is cheap to fix now
 * and awkward to find later.
 */
final class RedundantIndexRule implements MigrationRule
{
    public function identifier(): string
    {
        return 'redundant-index';
    }

    public function analyze(MigrationContext $context): array
    {
        $findings = [];

        foreach ($context->statement->operations as $operation) {
            if ($operation->method !== 'index') {
                // Only plain indexes. A unique index enforces a constraint the
                // composite one does not, so it is never redundant against it.
                continue;
            }

            $columns = $operation->columns();

            if ($columns === []) {
                continue;
            }

            $covering = $this->covering($context, $columns);

            if ($covering instanceof Index) {
                $findings[] = $this->finding($context, $operation, $columns, $covering);
            }
        }

        return $findings;
    }

    /**
     * An existing index that already leads with exactly these columns.
     *
     * @param  list<string>  $columns
     */
    private function covering(MigrationContext $context, array $columns): ?Index
    {
        $table = $context->liveTable();

        if (! $table instanceof Table) {
            return null;
        }

        foreach ($table->indexes as $index) {
            if ($index->columns === $columns) {
                // Same columns exactly — that is a duplicate, and `add-index` and the
                // engine will both have something to say. Not this rule's business.
                continue;
            }

            if (count($index->columns) <= count($columns)) {
                continue;
            }

            if (array_slice($index->columns, 0, count($columns)) === $columns) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $columns
     */
    private function finding(
        MigrationContext $context,
        Operation $operation,
        array $columns,
        Index $covering,
    ): MigrationFinding {
        $wanted = '('.implode(', ', $columns).')';

        return $context->finding(
            rule: $this->identifier(),
            risk: RiskLevel::Low,
            message: 'REDUNDANT INDEX '.($context->tableName() ?? '<unresolved>').' '.$wanted,
            explanation: 'The table already has `'.$covering->name.'` on ('.implode(', ', $covering->columns)
                .'), and an index can be used for any leading subset of its columns — so that one already '
                .'serves every lookup this one would. The new index adds write cost on every insert, '
                .'update and delete, and disk to hold it, for no read it can satisfy that the existing '
                .'index cannot.',
            suggestion: 'Drop this index from the migration and rely on `'.$covering->name.'`. If the '
                .'intent was a different access method or a partial index, say so explicitly — Difflock '
                .'compares columns and order only.',
            subject: $operation->stringArgument(1) ?? implode(', ', $columns),
            subjectType: Subject::Index,
            reversible: $context->reversible(),
            operation: $operation,
        );
    }
}
