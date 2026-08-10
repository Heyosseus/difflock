<?php

declare(strict_types=1);

namespace Difflock\Diff;

use Difflock\Contracts\SchemaDiffer;
use Difflock\Schema\Column;
use Difflock\Schema\DatabaseSchema;
use Difflock\Schema\ForeignKey;
use Difflock\Schema\Index;
use Difflock\Schema\Table;

/**
 * Compares two schemas, field by field.
 *
 * Two rules govern the whole of it.
 *
 * The first is that only fields *both* sides reported are compared. Each value
 * object's `comparable()` leaves out anything the driver did not tell us, and the
 * intersection of the two sets is what gets checked. This is what lets a MySQL
 * schema be compared against a PostgreSQL one without every integer column coming
 * back as changed because only one of them has an opinion about `unsigned`.
 *
 * The second is that a difference is reported *once*, at the level it happened. A
 * dropped table does not also report every one of its columns as dropped.
 */
final class SchemaComparator implements SchemaDiffer
{
    public function diff(DatabaseSchema $from, DatabaseSchema $to): SchemaDiff
    {
        $tables = [];

        foreach ($to->tables as $name => $table) {
            $previous = $from->table($name);

            if (! $previous instanceof Table) {
                $tables[] = new TableDiff($name, ChangeType::Added);

                continue;
            }

            $diff = $this->table($previous, $table);

            if ($diff instanceof TableDiff) {
                $tables[] = $diff;
            }
        }

        foreach ($from->tables as $name => $table) {
            if (! $to->hasTable($name)) {
                $tables[] = new TableDiff($name, ChangeType::Removed);
            }
        }

        usort($tables, static fn (TableDiff $a, TableDiff $b): int => strcmp($a->name, $b->name));

        return new SchemaDiff($tables, $from->connection, $to->connection);
    }

    /** Null when the two tables are identical in everything Difflock compares. */
    private function table(Table $from, Table $to): ?TableDiff
    {
        $columns = $this->columns($from, $to);
        $indexes = $this->indexes($from, $to);
        $foreignKeys = $this->foreignKeys($from, $to);

        if ($columns === [] && $indexes === [] && $foreignKeys === []) {
            return null;
        }

        return new TableDiff($to->name, ChangeType::Changed, $columns, $indexes, $foreignKeys);
    }

    /**
     * @return list<ColumnDiff>
     */
    private function columns(Table $from, Table $to): array
    {
        $diffs = [];

        foreach ($to->columns as $name => $column) {
            $previous = $from->column($name);

            if (! $previous instanceof Column) {
                $diffs[] = new ColumnDiff($name, ChangeType::Added, to: $column);

                continue;
            }

            $changes = $this->changes($previous->comparable(), $column->comparable());

            if ($changes !== []) {
                $diffs[] = new ColumnDiff($name, ChangeType::Changed, $previous, $column, $changes);
            }
        }

        foreach ($from->columns as $name => $column) {
            if (! $to->hasColumn($name)) {
                $diffs[] = new ColumnDiff($name, ChangeType::Removed, from: $column);
            }
        }

        usort($diffs, static fn (ColumnDiff $a, ColumnDiff $b): int => strcmp($a->name, $b->name));

        return $diffs;
    }

    /**
     * @return list<IndexDiff>
     */
    private function indexes(Table $from, Table $to): array
    {
        $diffs = [];

        foreach ($to->indexes as $name => $index) {
            $previous = $from->index($name);

            if (! $previous instanceof Index) {
                $diffs[] = new IndexDiff($name, ChangeType::Added, to: $index);

                continue;
            }

            $changes = $this->changes($previous->comparable(), $index->comparable());

            if ($changes !== []) {
                $diffs[] = new IndexDiff($name, ChangeType::Changed, $previous, $index, $changes);
            }
        }

        foreach ($from->indexes as $name => $index) {
            if (! $to->index($name) instanceof Index) {
                $diffs[] = new IndexDiff($name, ChangeType::Removed, from: $index);
            }
        }

        usort($diffs, static fn (IndexDiff $a, IndexDiff $b): int => strcmp($a->name, $b->name));

        return $diffs;
    }

    /**
     * @return list<ForeignKeyDiff>
     */
    private function foreignKeys(Table $from, Table $to): array
    {
        $diffs = [];

        foreach ($to->foreignKeys as $name => $key) {
            $previous = $from->foreignKey($name);

            if (! $previous instanceof ForeignKey) {
                $diffs[] = new ForeignKeyDiff($name, ChangeType::Added, to: $key);

                continue;
            }

            $changes = $this->changes($previous->comparable(), $key->comparable());

            if ($changes !== []) {
                $diffs[] = new ForeignKeyDiff($name, ChangeType::Changed, $previous, $key, $changes);
            }
        }

        foreach ($from->foreignKeys as $name => $key) {
            if (! $to->foreignKey($name) instanceof ForeignKey) {
                $diffs[] = new ForeignKeyDiff($name, ChangeType::Removed, from: $key);
            }
        }

        usort($diffs, static fn (ForeignKeyDiff $a, ForeignKeyDiff $b): int => strcmp($a->name, $b->name));

        return $diffs;
    }

    /**
     * The fields that differ, among the fields both sides actually reported.
     *
     * A field only one side carries is not a difference — it is one driver knowing
     * something the other cannot express. Reporting it would make every
     * cross-driver comparison useless, and every same-driver comparison is
     * unaffected because both sides then report the same set.
     *
     * @param  array<string, scalar>  $from
     * @param  array<string, scalar>  $to
     * @return array<string, array{from: scalar|null, to: scalar|null}>
     */
    private function changes(array $from, array $to): array
    {
        $changes = [];

        foreach ($to as $field => $value) {
            if (! array_key_exists($field, $from)) {
                continue;
            }

            if ($from[$field] !== $value) {
                $changes[$field] = ['from' => $from[$field], 'to' => $value];
            }
        }

        ksort($changes);

        return $changes;
    }
}
