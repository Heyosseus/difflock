<?php

declare(strict_types=1);

namespace Difflock\Schema;

/**
 * One table and everything Difflock knows about its structure.
 *
 * Columns, indexes and foreign keys are each keyed by name and sorted by it, so two
 * inspections of equivalent schemas produce equal objects regardless of the order
 * the driver happened to return them in. Column *position* is deliberately not part
 * of that: drivers disagree about it, most of them cannot change it without a table
 * rewrite, and a diff that reported every column as moved because one was added in
 * the middle would be noise rather than information.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class Table
{
    /** @var array<string, Column> */
    public array $columns;

    /** @var array<string, Index> */
    public array $indexes;

    /** @var array<string, ForeignKey> */
    public array $foreignKeys;

    /**
     * @param  list<Column>  $columns
     * @param  list<Index>  $indexes
     * @param  list<ForeignKey>  $foreignKeys
     */
    public function __construct(
        public string $name,
        array $columns = [],
        array $indexes = [],
        array $foreignKeys = [],
        public ?string $comment = null,
    ) {
        $this->columns = $this->keyed($columns);
        $this->indexes = $this->keyed($indexes);
        $this->foreignKeys = $this->keyed($foreignKeys);
    }

    public function column(string $name): ?Column
    {
        return $this->columns[$name] ?? null;
    }

    public function hasColumn(string $name): bool
    {
        return isset($this->columns[$name]);
    }

    public function index(string $name): ?Index
    {
        return $this->indexes[$name] ?? null;
    }

    public function foreignKey(string $name): ?ForeignKey
    {
        return $this->foreignKeys[$name] ?? null;
    }

    /**
     * The index covering exactly these columns in this order, if the table has one.
     *
     * Laravel's schema builder derives index names from the table and columns, but an
     * application is free to name them itself, so a rule asking "is this column
     * indexed" has to ask by columns rather than by name.
     *
     * @param  list<string>  $columns
     */
    public function indexOn(array $columns): ?Index
    {
        foreach ($this->indexes as $index) {
            if ($index->columns === $columns) {
                return $index;
            }
        }

        return null;
    }

    /** The same table with its columns' free-text fields dropped. */
    public function redacted(bool $keepDefaults, bool $keepComments): self
    {
        if ($keepDefaults && $keepComments) {
            return $this;
        }

        return new self(
            name: $this->name,
            columns: array_values(array_map(
                static fn (Column $column): Column => $column->redacted($keepDefaults, $keepComments),
                $this->columns,
            )),
            indexes: array_values($this->indexes),
            foreignKeys: array_values($this->foreignKeys),
            comment: $keepComments ? $this->comment : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'comment' => $this->comment,
            'columns' => array_map(static fn (Column $column): array => $column->toArray(), $this->columns),
            'indexes' => array_map(static fn (Index $index): array => $index->toArray(), $this->indexes),
            'foreign_keys' => array_map(
                static fn (ForeignKey $key): array => $key->toArray(),
                $this->foreignKeys,
            ),
        ];
    }

    /**
     * @template TItem of Column|Index|ForeignKey
     *
     * @param  list<TItem>  $items
     * @return array<string, TItem>
     */
    private function keyed(array $items): array
    {
        $keyed = [];

        foreach ($items as $item) {
            $keyed[$item->name] = $item;
        }

        ksort($keyed);

        return $keyed;
    }
}
