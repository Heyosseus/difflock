<?php

declare(strict_types=1);

namespace Difflock\Schema;

use Difflock\Exceptions\InvalidSnapshot;
use JsonException;

/**
 * A schema written to disk, and read back.
 *
 * The snapshot is what makes drift detection honest. Difflock does not try to
 * reconstruct an "expected" schema by reading migration source — migrations are
 * arbitrary PHP and that reconstruction cannot be made reliable — so instead it
 * compares the live schema against one that was *actually observed* and recorded on
 * purpose. Commit the file, and drift means "this database no longer matches the
 * schema we agreed on", which is a claim that can be checked.
 *
 * The format is versioned. A snapshot from a future version is refused rather than
 * half-understood.
 */
final class SchemaSnapshot
{
    /** Bumped only when the on-disk shape changes incompatibly. */
    public const int VERSION = 1;

    public static function encode(DatabaseSchema $schema, string $generatedAt): string
    {
        return json_encode([
            'difflock' => self::VERSION,
            'generated_at' => $generatedAt,
            'schema' => $schema->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    public static function decode(string $json, string $path): DatabaseSchema
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidSnapshot::at($path, $exception->getMessage());
        }

        if (! is_array($decoded) || ! isset($decoded['schema']) || ! is_array($decoded['schema'])) {
            throw InvalidSnapshot::at($path, 'it has no schema in it');
        }

        $version = $decoded['difflock'] ?? null;

        if (! is_int($version) || $version > self::VERSION) {
            throw InvalidSnapshot::at(
                $path,
                'it was written by a newer version of Difflock than the one installed',
            );
        }

        return self::schema($decoded['schema']);
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function schema(array $data): DatabaseSchema
    {
        $tables = [];

        foreach (self::rows($data, 'tables') as $table) {
            $name = self::string($table, 'name');

            if ($name === null) {
                continue;
            }

            $tables[] = new Table(
                name: $name,
                columns: self::columns($table),
                indexes: self::indexes($table),
                foreignKeys: self::foreignKeys($table),
                comment: self::string($table, 'comment'),
            );
        }

        return new DatabaseSchema(
            $tables,
            self::string($data, 'driver'),
            self::string($data, 'connection'),
        );
    }

    /**
     * @param  array<mixed>  $table
     * @return list<Column>
     */
    private static function columns(array $table): array
    {
        $columns = [];

        foreach (self::rows($table, 'columns') as $column) {
            $name = self::string($column, 'name');

            if ($name === null) {
                continue;
            }

            $columns[] = new Column(
                name: $name,
                type: self::string($column, 'type') ?? '',
                definition: self::string($column, 'definition') ?? '',
                nullable: (bool) ($column['nullable'] ?? false),
                default: self::string($column, 'default'),
                autoIncrement: (bool) ($column['auto_increment'] ?? false),
                unsigned: self::bool($column, 'unsigned'),
                length: self::int($column, 'length'),
                precision: self::int($column, 'precision'),
                scale: self::int($column, 'scale'),
                comment: self::string($column, 'comment'),
            );
        }

        return $columns;
    }

    /**
     * @param  array<mixed>  $table
     * @return list<Index>
     */
    private static function indexes(array $table): array
    {
        $indexes = [];

        foreach (self::rows($table, 'indexes') as $index) {
            $name = self::string($index, 'name');

            if ($name === null) {
                continue;
            }

            $indexes[] = new Index(
                name: $name,
                columns: self::strings($index, 'columns'),
                unique: (bool) ($index['unique'] ?? false),
                primary: (bool) ($index['primary'] ?? false),
                type: self::string($index, 'type'),
            );
        }

        return $indexes;
    }

    /**
     * @param  array<mixed>  $table
     * @return list<ForeignKey>
     */
    private static function foreignKeys(array $table): array
    {
        $keys = [];

        foreach (self::rows($table, 'foreign_keys') as $key) {
            $name = self::string($key, 'name');
            $foreignTable = self::string($key, 'foreign_table');
            if ($name === null) {
                continue;
            }
            if ($foreignTable === null) {
                continue;
            }

            $keys[] = new ForeignKey(
                name: $name,
                columns: self::strings($key, 'columns'),
                foreignTable: $foreignTable,
                foreignColumns: self::strings($key, 'foreign_columns'),
                onDelete: self::string($key, 'on_delete'),
                onUpdate: self::string($key, 'on_update'),
            );
        }

        return $keys;
    }

    /**
     * @param  array<mixed>  $data
     * @return list<array<mixed>>
     */
    private static function rows(array $data, string $key): array
    {
        $rows = $data[$key] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $kept = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function bool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param  array<mixed>  $data
     * @return list<string>
     */
    private static function strings(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

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
