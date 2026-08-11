<?php

declare(strict_types=1);

namespace Difflock\Migration\Rules;

use Difflock\Contracts\MigrationRule;
use Difflock\Migration\MigrationContext;
use Difflock\Migration\MigrationFinding;
use Difflock\Migration\Parser\Modifier;
use Difflock\Migration\Parser\Operation;
use Difflock\Migration\Subject;
use Difflock\Risk\RiskLevel;

/**
 * Foreign keys: added, removed, and — the one that costs people data — given a
 * cascade.
 *
 * `cascadeOnDelete()` is four keystrokes that turn `$user->delete()` into a delete of
 * every order, every invoice and every line on them, with no application code
 * involved and nothing in the log to say it happened. It is reported at high on the
 * migration that introduces it, because that is the last moment anybody looks at it
 * on purpose.
 *
 * Adding a constraint to a populated table is reported for a different reason: the
 * database validates every existing row against it, which both costs something and
 * fails outright if any row is already an orphan. Removing one is reported because
 * referential integrity stops being the database's job from that point on.
 */
final class ForeignKeyRule implements MigrationRule
{
    /**
     * Chained calls that make a delete of the parent row remove the children.
     *
     * @var list<string>
     */
    private const array CASCADES = ['cascadeOnDelete', 'cascadeOnUpdate'];

    public function identifier(): string
    {
        return 'foreign-key';
    }

    public function analyze(MigrationContext $context): array
    {
        $findings = [];

        foreach ($context->statement->operations as $operation) {
            if ($operation->method === 'dropForeign') {
                $findings[] = $this->dropped($context, $operation);

                continue;
            }

            $cascade = $this->cascade($operation);

            if ($cascade !== null) {
                $findings[] = $this->cascading($context, $operation, $cascade);
            }

            if ($this->adds($operation) && $context->statement->isAlter()) {
                $findings[] = $this->added($context, $operation);
            }
        }

        return $findings;
    }

    private function dropped(MigrationContext $context, Operation $operation): MigrationFinding
    {
        $name = $operation->stringArgument(0) ?? implode(', ', $operation->columns());

        return $context->finding(
            rule: $this->identifier(),
            risk: RiskLevel::High,
            message: 'DROP FOREIGN KEY '.($context->tableName() ?? '<unresolved>')
                .($name === '' ? '' : ' ('.$name.')'),
            explanation: 'Referential integrity for this relationship stops being enforced by the '
                .'database. Rows pointing at parents that no longer exist become possible, and they '
                .'accumulate quietly — nothing fails at the moment one is created.',
            suggestion: 'If the constraint is being dropped to make another migration possible, add it '
                .'back in the same deploy. If it is going for good, be sure the application really is '
                .'the only thing writing this table.',
            subject: $name === '' ? null : $name,
            subjectType: Subject::Constraint,
            reversible: $context->reversible(),
            operation: $operation,
        );
    }

    private function cascading(MigrationContext $context, Operation $operation, string $action): MigrationFinding
    {
        $column = $operation->stringArgument(0) ?? implode(', ', $operation->columns());

        return $context->finding(
            rule: $this->identifier(),
            risk: RiskLevel::High,
            message: 'FOREIGN KEY '.($context->tableName() ?? '<unresolved>')
                .($column === '' ? '' : '.'.$column).' '.strtoupper($action),
            explanation: 'A cascading delete removes child rows whenever a parent row is deleted, '
                .'inside the database and without the application being involved. A single '
                .'`$parent->delete()` — or one `DELETE` run by hand — can therefore remove far more '
                .'than the row it names, and model events, observers and soft deletes do not run for '
                .'any of it.',
            suggestion: 'Consider `restrictOnDelete()` or `nullOnDelete()` unless the child rows are '
                .'genuinely worthless without the parent, and make sure the reach of the cascade is '
                .'understood — cascades chain through further cascading keys.',
            subject: $column === '' ? null : $column,
            subjectType: Subject::Constraint,
            destructive: true,
            reversible: $context->reversible(),
            operation: $operation,
        );
    }

    private function added(MigrationContext $context, Operation $operation): MigrationFinding
    {
        $column = $operation->stringArgument(0) ?? implode(', ', $operation->columns());
        $size = $context->database->describeSize($context->tableName());
        $rows = $context->rows();

        return $context->finding(
            rule: $this->identifier(),
            risk: $rows !== null && $rows === 0 ? RiskLevel::Low : RiskLevel::Medium,
            message: 'ADD FOREIGN KEY '.($context->tableName() ?? '<unresolved>')
                .($column === '' ? '' : '.'.$column),
            explanation: 'Adding a constraint to an existing table makes the database validate every '
                .'row already in it. If any of them points at a parent that is not there, the '
                .'statement is refused and the migration stops partway through.',
            suggestion: 'Find and resolve the orphans before deploying — a `whereNotExists` against the '
                .'parent table is usually enough to know whether there are any.',
            subject: $column === '' ? null : $column,
            subjectType: Subject::Constraint,
            reversible: $context->reversible(),
            operation: $operation,
            context: $size ?? 'table size could not be determined',
        );
    }

    /** Whether the chain introduces a foreign key at all. */
    private function adds(Operation $operation): bool
    {
        return $operation->method === 'foreign' || $operation->hasModifier('constrained');
    }

    /**
     * The cascading action the chain declares, or null if it declares none.
     *
     * Both spellings are recognised — the fluent `cascadeOnDelete()` and the literal
     * `onDelete('cascade')` — because a rule that only knew one of them would be a
     * rule you could not rely on.
     */
    private function cascade(Operation $operation): ?string
    {
        foreach ($operation->modifiers as $modifier) {
            if (in_array($modifier->method, self::CASCADES, true)) {
                return $modifier->method === 'cascadeOnDelete' ? 'on delete cascade' : 'on update cascade';
            }

            $literal = $this->literalCascade($modifier);

            if ($literal !== null) {
                return $literal;
            }
        }

        return null;
    }

    private function literalCascade(Modifier $modifier): ?string
    {
        if ($modifier->method !== 'onDelete' && $modifier->method !== 'onUpdate') {
            return null;
        }

        $action = $modifier->argument(0);

        if (! is_string($action) || strtolower(trim($action)) !== 'cascade') {
            return null;
        }

        return $modifier->method === 'onDelete' ? 'on delete cascade' : 'on update cascade';
    }
}
