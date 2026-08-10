<?php

declare(strict_types=1);

namespace Difflock\Migration\Rules;

use Difflock\Contracts\MigrationRule;
use Difflock\Migration\MigrationContext;
use Difflock\Migration\Subject;
use Difflock\Risk\RiskLevel;
use Difflock\Support\Bytes;

/**
 * Context, not a complaint: this migration is altering a table big enough that
 * everything else in the report deserves rereading.
 *
 * The rule fires only when the size is actually known and actually large. It invents
 * no precision — if the driver would not say how big the table is, this rule says
 * nothing at all, rather than warning about a table that might have four rows in it.
 *
 * It reports medium and never more. A large table does not make an operation
 * dangerous by itself; the other rules judge the operations. This one exists so that
 * "add an index" and "add an index to the eleven-million-row orders table" do not
 * read identically in a review.
 */
final class LargeTableRule implements MigrationRule
{
    public function identifier(): string
    {
        return 'large-table';
    }

    public function analyze(MigrationContext $context): array
    {
        if (! $context->statement->isAlter() || $context->statement->operations === []) {
            return [];
        }

        $table = $context->tableName();
        $rows = $context->rows();

        if (! $context->database->thresholds->isLarge($rows)) {
            return [];
        }

        $bytes = $context->database->bytes($table);

        return [$context->finding(
            rule: $this->identifier(),
            risk: RiskLevel::Medium,
            message: 'ALTER on a large table: '.($table ?? '<unresolved>')
                .' ('.$context->database->describeSize($table).')',
            explanation: 'The table is above the size Difflock is configured to treat as large'
                .($bytes === null ? '' : ', and occupies about '.Bytes::human($bytes))
                .'. Any statement that rewrites it, scans it, or holds a lock on it is felt for as '
                .'long as that takes — how long, and what is blocked meanwhile, depends on the '
                .'engine and version rather than on anything visible in the migration.',
            suggestion: 'Read the other findings for this migration with the size in mind, and '
                .'consider running the statement outside the deploy window.',
            subject: $table,
            subjectType: Subject::Table,
            reversible: $context->reversible(),
        )];
    }
}
