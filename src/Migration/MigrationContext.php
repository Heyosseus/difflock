<?php

declare(strict_types=1);

namespace Difflock\Migration;

use Difflock\Migration\Parser\Operation;
use Difflock\Migration\Parser\ParsedMigration;
use Difflock\Migration\Parser\SchemaStatement;
use Difflock\Risk\RiskLevel;
use Difflock\Schema\Table;

/**
 * One `Schema::` statement, the migration it came from, and the database it is aimed
 * at — everything a rule is given and everything it is allowed to see.
 *
 * A rule receives one context per schema statement rather than one per migration, so
 * a rule about dropping columns never has to loop over statements to find the one it
 * cares about, and a migration touching three tables produces three contexts with
 * three separate row counts.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class MigrationContext
{
    public function __construct(
        public ParsedMigration $migration,
        public SchemaStatement $statement,
        public DatabaseContext $database,
    ) {}

    public function migrationName(): string
    {
        return $this->migration->name;
    }

    /** The table this statement targets, or null when the name was not a literal. */
    public function tableName(): ?string
    {
        return $this->statement->table;
    }

    /** The table as it exists in the database right now, or null if it does not. */
    public function liveTable(): ?Table
    {
        return $this->database->table($this->statement->table);
    }

    /** Roughly how many rows the target table holds, or null when unknown. */
    public function rows(): ?int
    {
        return $this->database->rows($this->statement->table);
    }

    /**
     * The operations in this statement whose chain begins with one of these methods.
     *
     * @return list<Operation>
     */
    public function operations(string ...$methods): array
    {
        $matched = [];

        foreach ($this->statement->operations as $operation) {
            if ($methods === [] || in_array($operation->method, $methods, true)) {
                $matched[] = $operation;
            }
        }

        return $matched;
    }

    /**
     * Whether the migration has a `down()` with a body.
     *
     * This says the author wrote a reversal, not that the reversal restores data.
     * See {@see MigrationFinding} for why the two are not the same thing.
     */
    public function reversible(): bool
    {
        return $this->migration->reversible;
    }

    /**
     * Build a finding, filling in everything that comes from the context.
     *
     * Rules use this rather than constructing findings by hand so that the migration
     * name, the table and the conditional flag are never accidentally left off.
     */
    public function finding(
        string $rule,
        RiskLevel $risk,
        string $message,
        string $explanation,
        ?string $suggestion = null,
        ?string $subject = null,
        Subject $subjectType = Subject::None,
        bool $destructive = false,
        bool $reversible = true,
        ?Operation $operation = null,
        ?string $context = null,
    ): MigrationFinding {
        return new MigrationFinding(
            rule: $rule,
            risk: $risk,
            migration: $this->migration->name,
            message: $message,
            explanation: $explanation,
            suggestion: $suggestion,
            table: $this->statement->table,
            subject: $subject,
            subjectType: $subjectType,
            destructive: $destructive,
            reversible: $reversible,
            line: $operation->line ?? $this->statement->line,
            conditional: $operation->conditional ?? $this->statement->conditional,
            context: $context,
        );
    }
}
