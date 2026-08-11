<?php

declare(strict_types=1);

namespace Difflock\Mcp\Tools;

use Difflock\Database\DatabaseContextFactory;
use Difflock\Mcp\Tool;
use Difflock\Schema\Column;
use Difflock\Schema\ForeignKey;
use Difflock\Schema\Index;
use Difflock\Schema\Table;

/**
 * "What am I actually dealing with here?"
 *
 * Everything Difflock knows about one table: its shape, how many rows it holds, what
 * is indexed and what points at it. The tool to call *before* writing a migration
 * rather than after.
 *
 * A model asked to "add a status column to orders" has no idea whether orders holds
 * eleven rows or eleven million, and that single fact decides whether `->nullable()`
 * is a nicety or the difference between a deploy and an outage.
 */
final readonly class TableContext implements Tool
{
    public function __construct(private DatabaseContextFactory $contexts) {}

    public function name(): string
    {
        return 'difflock_table_context';
    }

    public function description(): string
    {
        return 'Describe a database table as it exists right now: columns with types and nullability, '
            .'indexes, foreign keys, and roughly how many rows it holds. Call this before writing a '
            .'migration that touches the table. Row count is null when the engine will not say, which '
            .'means unknown and never zero. If the table does not exist, exists is false.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => ['type' => 'string', 'description' => 'The table name.'],
            ],
            'required' => ['table'],
        ];
    }

    public function handle(array $arguments): array
    {
        $name = $arguments['table'] ?? null;

        if (! is_string($name) || $name === '') {
            return ['error' => 'A table name is required.'];
        }

        $database = $this->contexts->make();

        if (! $database->available) {
            return ['error' => 'The database could not be reached, so nothing can be said about '.$name.'.'];
        }

        $table = $database->table($name);

        if (! $table instanceof Table) {
            return [
                'table' => $name,
                'exists' => false,
                'known_tables' => $database->schema->tableNames(),
            ];
        }

        return [
            'table' => $name,
            'exists' => true,
            'driver' => $database->driver(),
            'rows' => $database->rows($name),
            'rows_are_estimates' => $database->statistics->approximate(),
            'bytes' => $database->bytes($name),
            'is_large' => $database->thresholds->isLarge($database->rows($name)),
            'columns' => array_values(array_map(
                static fn (Column $column): array => [
                    'name' => $column->name,
                    'type' => $column->definition,
                    'nullable' => $column->nullable,
                    'default' => $column->default,
                    'auto_increment' => $column->autoIncrement,
                ],
                $table->columns,
            )),
            'indexes' => array_values(array_map(
                static fn (Index $index): array => [
                    'name' => $index->name,
                    'columns' => $index->columns,
                    'unique' => $index->unique,
                    'primary' => $index->primary,
                    'reads' => $database->indexScans($name, $index->name),
                ],
                $table->indexes,
            )),
            'foreign_keys' => array_values(array_map(
                static fn (ForeignKey $key): array => [
                    'name' => $key->name,
                    'columns' => $key->columns,
                    'references' => $key->foreignTable.'('.implode(', ', $key->foreignColumns).')',
                    'on_delete' => $key->onDelete,
                ],
                $table->foreignKeys,
            )),
        ];
    }
}
