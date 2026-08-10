<?php

declare(strict_types=1);

namespace Difflock\Diff;

/**
 * Everything that changed about one table.
 *
 * A table that was added or removed carries no column diffs: listing every column of
 * a dropped table as also dropped is noise, and the table line already says it.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class TableDiff
{
    /**
     * @param  list<ColumnDiff>  $columns
     * @param  list<IndexDiff>  $indexes
     * @param  list<ForeignKeyDiff>  $foreignKeys
     */
    public function __construct(
        public string $name,
        public ChangeType $type,
        public array $columns = [],
        public array $indexes = [],
        public array $foreignKeys = [],
    ) {}

    /**
     * How many individual differences this table contributes.
     *
     * An added or removed table counts as one, not as one per column: the reader is
     * being told a table appeared, and the number at the bottom of a diff should
     * agree with the number of lines above it.
     */
    public function count(): int
    {
        if ($this->type !== ChangeType::Changed) {
            return 1;
        }

        return count($this->columns) + count($this->indexes) + count($this->foreignKeys);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'table' => $this->name,
            'change' => $this->type->value,
            'columns' => array_map(static fn (ColumnDiff $diff): array => $diff->toArray(), $this->columns),
            'indexes' => array_map(static fn (IndexDiff $diff): array => $diff->toArray(), $this->indexes),
            'foreign_keys' => array_map(
                static fn (ForeignKeyDiff $diff): array => $diff->toArray(),
                $this->foreignKeys,
            ),
        ];
    }
}
