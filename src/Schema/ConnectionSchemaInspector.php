<?php

declare(strict_types=1);

namespace Difflock\Schema;

use Difflock\Contracts\SchemaInspector;
use Difflock\Exceptions\UnreadableConnection;
use Difflock\Support\TypeDefinition;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Schema\Builder;

/**
 * Reads a schema through Laravel's own schema builder.
 *
 * Laravel 11 moved introspection into the framework — `getTables()`, `getColumns()`,
 * `getIndexes()` and `getForeignKeys()` are answered per driver by the framework's
 * own grammars — so Difflock needs no Doctrine DBAL and carries no driver-specific
 * SQL of its own. Everything driver-shaped that remains lives in
 * {@see TypeDefinition}, which reads a length out of a type string, and in the one
 * place below that decides whether "unsigned" is a concept this driver even has.
 *
 * The whole inspection is four queries per table plus one for the table list, and
 * the result is memoised per connection for the life of the object: a single command
 * that diffs and then lints reads the schema once.
 */
final class ConnectionSchemaInspector implements SchemaInspector
{
    /** @var array<string, DatabaseSchema> */
    private array $inspected = [];

    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly ?string $default = null,
    ) {}

    public function inspect(?string $connection = null): DatabaseSchema
    {
        $name = $connection ?? $this->default;

        return $this->inspected[$name ?? ''] ??= $this->read($name);
    }

    private function read(?string $name): DatabaseSchema
    {
        $connection = $this->connections->connection($name);

        // The resolver's return type is the narrow query interface; introspection
        // lives on the concrete connection, and every driver Laravel ships returns
        // one. A connection that does not is one Difflock cannot read, and saying so
        // is better than half-reading it.
        if (! $connection instanceof Connection) {
            throw new UnreadableConnection(
                'Difflock can only inspect a connection that exposes a schema builder.',
            );
        }

        $builder = $connection->getSchemaBuilder();
        $driver = $connection->getDriverName();

        $tables = [];

        foreach ($builder->getTables() as $table) {
            $tableName = $this->name($table);

            if ($tableName === null) {
                continue;
            }

            $tables[] = new Table(
                name: $tableName,
                columns: $this->columns($builder, $tableName, $driver),
                indexes: $this->indexes($builder, $tableName),
                foreignKeys: $this->foreignKeys($builder, $tableName),
                comment: $this->string($table, 'comment'),
            );
        }

        return new DatabaseSchema($tables, $driver, $connection->getName());
    }

    /**
     * @return list<Column>
     */
    private function columns(Builder $builder, string $table, string $driver): array
    {
        $columns = [];

        foreach ($builder->getColumns($table) as $column) {
            $name = $this->name($column);

            if ($name === null) {
                continue;
            }

            $definition = $this->string($column, 'type') ?? '';
            $type = $this->string($column, 'type_name') ?? $definition;

            $columns[] = new Column(
                name: $name,
                type: strtolower($type),
                definition: $definition,
                nullable: (bool) ($column['nullable'] ?? false),
                default: $this->string($column, 'default'),
                autoIncrement: (bool) ($column['auto_increment'] ?? false),
                unsigned: TypeDefinition::unsigned($definition, $driver),
                length: TypeDefinition::length($type, $definition),
                precision: TypeDefinition::precision($type, $definition),
                scale: TypeDefinition::scale($type, $definition),
                comment: $this->string($column, 'comment'),
            );
        }

        return $columns;
    }

    /**
     * @return list<Index>
     */
    private function indexes(Builder $builder, string $table): array
    {
        $indexes = [];

        foreach ($builder->getIndexes($table) as $index) {
            $name = $this->name($index);

            if ($name === null) {
                continue;
            }

            $indexes[] = new Index(
                name: $name,
                columns: $this->strings($index, 'columns'),
                unique: (bool) ($index['unique'] ?? false),
                primary: (bool) ($index['primary'] ?? false),
                type: $this->string($index, 'type'),
            );
        }

        return $indexes;
    }

    /**
     * @return list<ForeignKey>
     */
    private function foreignKeys(Builder $builder, string $table): array
    {
        $keys = [];

        foreach ($builder->getForeignKeys($table) as $key) {
            $foreignTable = $this->string($key, 'foreign_table');

            if ($foreignTable === null) {
                continue;
            }

            $columns = $this->strings($key, 'columns');

            $keys[] = new ForeignKey(
                name: $this->name($key) ?? $this->derivedKeyName($table, $columns),
                columns: $columns,
                foreignTable: $foreignTable,
                foreignColumns: $this->strings($key, 'foreign_columns'),
                onDelete: $this->lower($key, 'on_delete'),
                onUpdate: $this->lower($key, 'on_update'),
            );
        }

        return $keys;
    }

    /**
     * A name for a foreign key the driver will not name.
     *
     * SQLite does not record constraint names at all, so `getForeignKeys()` comes
     * back with `name => null`. Dropping the key would hide real structure, and
     * keying it on an empty string would collide with the next unnamed one, so it
     * gets the name Laravel's schema builder would have generated for it —
     * `orders_customer_id_foreign`. That is deterministic, it is the same on both
     * sides of a comparison, and on MySQL and PostgreSQL, where the constraint made
     * by the same migration really is called that, the two agree.
     *
     * @param  list<string>  $columns
     */
    private function derivedKeyName(string $table, array $columns): string
    {
        return $table.'_'.implode('_', $columns).'_foreign';
    }

    /**
     * The `name` key, when the driver gave one that is worth having.
     *
     * SQLite names an unnamed unique constraint `sqlite_autoindex_users_1`, and a
     * foreign key it was never given a name for comes back with none at all. Both are
     * still returned — dropping them would hide real structure — but a row with no
     * usable name is skipped rather than keyed on an empty string, where it would
     * collide with the next one.
     */
    private function name(mixed $row): ?string
    {
        $name = $this->string($row, 'name');

        return $name === '' ? null : $name;
    }

    /**
     * Read one field out of a metadata row.
     *
     * The four readers below all take `mixed` rather than an array, and it is not
     * laziness. Laravel annotates the shape of what `getTables()`, `getColumns()`,
     * `getIndexes()` and `getForeignKeys()` return, and the annotations differ
     * between framework versions — Laravel 12 describes foreign keys as a plain
     * `array<int, mixed>` where 13 describes the row shape. Typing these to whatever
     * one version promises makes the package fail static analysis on another, and
     * would be believing an annotation about data that is untyped at runtime anyway.
     *
     * So each of them checks. A row that is not an array, or a field that is not the
     * kind of value expected, comes back null or empty, and the caller skips it.
     */
    private function string(mixed $row, string $key): ?string
    {
        if (! is_array($row)) {
            return null;
        }

        $value = $row[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    private function lower(mixed $row, string $key): ?string
    {
        $value = $this->string($row, $key);

        return $value === null || $value === '' ? null : strtolower($value);
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $row, string $key): array
    {
        $value = is_array($row) ? $row[$key] ?? [] : [];

        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $strings[] = (string) $item;
            }
        }

        return $strings;
    }
}
