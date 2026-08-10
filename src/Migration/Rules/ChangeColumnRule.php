<?php

declare(strict_types=1);

namespace Difflock\Migration\Rules;

use Difflock\Contracts\MigrationRule;
use Difflock\Migration\Blueprint;
use Difflock\Migration\MigrationContext;
use Difflock\Migration\MigrationFinding;
use Difflock\Migration\Parser\Modifier;
use Difflock\Migration\Parser\Operation;
use Difflock\Migration\Subject;
use Difflock\Risk\RiskLevel;
use Difflock\Schema\Column;
use Difflock\Support\TypeFamily;

/**
 * `$table->string('email', 320)->change()`.
 *
 * The only rule whose risk is genuinely computed rather than looked up, because
 * `change()` covers everything from widening a `varchar` — which costs nothing — to
 * turning a nullable text column into a NOT NULL integer, which can fail partway
 * through and leave the deploy stuck.
 *
 * It compares the declaration against the column as it exists now, and each concern
 * it finds contributes a risk level; the finding carries the highest.
 *
 * Two things it deliberately does not claim. It does not say whether the change
 * rewrites the table or takes a lock: that depends on the engine, the version and
 * sometimes the row contents, and no static reader can know it. And where the live
 * column cannot be read — no database, or a table that does not exist yet — it
 * reports medium and says the comparison was not possible, rather than assuming the
 * change is small.
 */
final class ChangeColumnRule implements MigrationRule
{
    public function identifier(): string
    {
        return 'change-column';
    }

    public function analyze(MigrationContext $context): array
    {
        $findings = [];

        foreach ($context->statement->operations as $operation) {
            if (! $operation->hasModifier('change')) {
                continue;
            }
            if (! Blueprint::isColumn($operation->method)) {
                continue;
            }
            $findings[] = $this->change($context, $operation);
        }

        return $findings;
    }

    private function change(MigrationContext $context, Operation $operation): MigrationFinding
    {
        $name = $operation->stringArgument(0) ?? (Blueprint::columnsOf($operation)[0] ?? '<unresolved>');
        $table = $context->tableName() ?? '<unresolved>';
        $live = $context->liveTable()?->column($name);
        $rows = $context->rows();

        $concerns = $live instanceof Column
            ? $this->concerns($operation, $live, $rows)
            : [[RiskLevel::Medium, 'The column could not be read from the database, so what is actually '
                .'changing about it could not be determined.']];

        $risk = RiskLevel::Safe;

        foreach ($concerns as [$level]) {
            $risk = $risk->max($level);
        }

        $explanation = implode(' ', array_map(
            static fn (array $concern): string => (string) $concern[1],
            $concerns,
        ));

        $explanation .= ' Whether an ALTER of this kind rewrites the table or takes a lock depends on '
            .'the database engine, its version and its configuration; Difflock does not claim to know '
            .'which of those applies here.';

        return $context->finding(
            rule: $this->identifier(),
            risk: $risk,
            message: 'CHANGE COLUMN '.$table.'.'.$name.$this->declaration($operation),
            explanation: trim($explanation),
            suggestion: $this->suggestion($risk),
            subject: $name,
            subjectType: Subject::Column,
            destructive: $risk === RiskLevel::High || $risk === RiskLevel::Critical,
            reversible: $context->reversible(),
            operation: $operation,
        );
    }

    /**
     * Each thing about the change that is worth a word, with the risk it carries.
     *
     * @return list<array{0: RiskLevel, 1: string}>
     */
    private function concerns(Operation $operation, Column $live, ?int $rows): array
    {
        $concerns = [];

        if (TypeFamily::changes($operation->method, $live->type)) {
            $concerns[] = [
                $rows === null || $rows > 0 ? RiskLevel::High : RiskLevel::Low,
                'The column is changing type family, from '
                    .strtoupper((string) TypeFamily::ofDatabase($live->type)).' to '
                    .strtoupper((string) TypeFamily::ofBlueprint($operation->method))
                    .'. Values the new type cannot represent are lost or rejected.',
            ];
        }

        if (! Blueprint::isNullable($operation) && $live->nullable) {
            $concerns[] = [
                match (true) {
                    $rows === null => RiskLevel::Medium,
                    $rows > 0 => RiskLevel::High,
                    default => RiskLevel::Low,
                },
                'The column is going from nullable to NOT NULL. If any existing row holds null, the '
                    .'statement fails and the migration stops partway through'
                    .($rows === null
                        ? ', and the number of rows could not be determined.'
                        : ($rows > 0 ? ', and the table is not empty.' : '.')),
            ];
        }

        if (Blueprint::isNullable($operation) && ! $live->nullable) {
            $concerns[] = [RiskLevel::Low, 'The column is going from NOT NULL to nullable, which '
                .'accepts everything it accepted before.'];
        }

        $concerns = [...$concerns, ...$this->lengths($operation, $live, $rows)];

        $default = $operation->modifier('default');

        if ($live->default !== null && ! $default instanceof Modifier) {
            $concerns[] = [RiskLevel::Medium, 'The column currently has a default and the new '
                .'definition does not declare one, so the default is dropped. Inserts that relied on it '
                .'will need to supply the value themselves.'];
        }

        if ($concerns === []) {
            $concerns[] = [RiskLevel::Low, 'Nothing Difflock compares — type family, nullability, '
                .'length, precision or default — differs from the column as it exists now.'];
        }

        return $concerns;
    }

    /**
     * @return list<array{0: RiskLevel, 1: string}>
     */
    private function lengths(Operation $operation, Column $live, ?int $rows): array
    {
        $length = $operation->intArgument(1);

        if ($length === null || $live->length === null) {
            return [];
        }

        if ($length < $live->length) {
            return [[
                $rows === null || $rows > 0 ? RiskLevel::High : RiskLevel::Low,
                'The length is being reduced from '.$live->length.' to '.$length.
                    '. Depending on the engine, values that no longer fit are either truncated or the '
                    .'statement is refused.',
            ]];
        }

        if ($length > $live->length) {
            return [[RiskLevel::Low, 'The length is being increased from '.$live->length.' to '
                .$length.', which every existing value already fits.']];
        }

        return [];
    }

    private function declaration(Operation $operation): string
    {
        $length = $operation->intArgument(1);

        return ' → '.strtoupper($operation->method).($length === null ? '' : '('.$length.')')
            .(Blueprint::isNullable($operation) ? ' NULL' : ' NOT NULL');
    }

    private function suggestion(RiskLevel $risk): ?string
    {
        return match ($risk) {
            RiskLevel::High, RiskLevel::Critical => 'Backfill or clean the affected rows in their own '
                .'migration first, so the ALTER itself has nothing left to fail on.',
            RiskLevel::Medium => 'Check the column against the database this will run on before '
                .'deploying — the comparison Difflock wanted to make was not available here.',
            default => null,
        };
    }
}
