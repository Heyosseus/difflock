<?php

declare(strict_types=1);

namespace Difflock\Migration\Parser;

/**
 * What static analysis could work out about one migration file.
 *
 * The `warnings` are as much a part of the result as the statements. A migration
 * that builds its schema in a loop, or drops a column whose name comes from config,
 * or reaches for `DB::statement()`, is one this parser has read *incompletely* — and
 * saying so is the difference between a tool you can trust and one that quietly
 * reports a clean bill of health because it did not understand the file.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class ParsedMigration
{
    /**
     * @param  list<SchemaStatement>  $statements  Everything `up()` does, in source order.
     * @param  bool  $reversible  Whether `down()` exists and has a body. Not whether it works.
     * @param  list<string>  $warnings  What the parser saw and could not fully read.
     */
    public function __construct(
        public string $name,
        public string $path,
        public array $statements = [],
        public bool $reversible = false,
        public array $warnings = [],
    ) {}

    /**
     * Every table the migration names, in the order they first appear.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $tables = [];

        foreach ($this->statements as $statement) {
            if ($statement->table !== null && ! in_array($statement->table, $tables, true)) {
                $tables[] = $statement->table;
            }
        }

        return $tables;
    }
}
