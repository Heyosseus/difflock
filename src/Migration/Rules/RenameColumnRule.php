<?php

declare(strict_types=1);

namespace Difflock\Migration\Rules;

use Difflock\Contracts\MigrationRule;
use Difflock\Migration\MigrationContext;
use Difflock\Migration\Subject;
use Difflock\Risk\RiskLevel;

/**
 * `renameColumn()` and `Schema::rename()`.
 *
 * High rather than critical: no data is lost, and the rename is genuinely
 * reversible. What breaks is the *application* — every query naming the old column
 * fails the instant the migration lands, and during a rolling deploy there is a
 * window in which the old code and the new schema are both live. That window is the
 * finding.
 */
final class RenameColumnRule implements MigrationRule
{
    public function identifier(): string
    {
        return 'rename-column';
    }

    public function analyze(MigrationContext $context): array
    {
        $findings = [];

        foreach ($context->statement->operations as $operation) {
            if ($operation->method === 'renameTable') {
                $findings[] = $context->finding(
                    rule: $this->identifier(),
                    risk: RiskLevel::High,
                    message: 'RENAME TABLE '.$this->name($operation->argument(0))
                        .' → '.$this->name($operation->argument(1)),
                    explanation: 'Every query naming the old table fails the moment this runs. During a '
                        .'rolling deploy, instances still running the previous release are among them.',
                    suggestion: 'Prefer creating the new table, backfilling, and switching reads over — '
                        .'or accept a brief window and deploy the rename with the code that expects it.',
                    subject: $this->name($operation->argument(0)),
                    subjectType: Subject::Table,
                    reversible: $context->reversible(),
                    operation: $operation,
                );

                continue;
            }

            if ($operation->method !== 'renameColumn') {
                continue;
            }

            $from = $this->name($operation->argument(0));
            $to = $this->name($operation->argument(1));

            $findings[] = $context->finding(
                rule: $this->identifier(),
                risk: RiskLevel::High,
                message: 'RENAME COLUMN '.($context->tableName() ?? '<unresolved>').'.'.$from.' → '.$to,
                explanation: 'The data survives, but every query, model cast, index name and raw '
                    .'expression naming `'.$from.'` stops working the moment this runs. On a rolling '
                    .'deploy the previous release is still serving traffic against the old name.',
                suggestion: 'The zero-downtime shape is: add the new column, write to both, backfill, '
                    .'switch reads, then drop the old one — four deploys instead of one, and no window '
                    .'where the running code is wrong.',
                subject: $from,
                subjectType: Subject::Column,
                reversible: $context->reversible(),
                operation: $operation,
            );
        }

        return $findings;
    }

    private function name(mixed $value): string
    {
        return is_string($value) ? $value : '<unresolved>';
    }
}
