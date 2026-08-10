<?php

declare(strict_types=1);

namespace Difflock\Diff;

/**
 * The difference between two schemas.
 *
 * Directional: `from` is the schema being compared against — a saved snapshot, or
 * another connection — and `to` is the one in front of you. So `+ phone` means the
 * live schema has a column the baseline did not.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class SchemaDiff
{
    /**
     * @param  list<TableDiff>  $tables
     */
    public function __construct(
        public array $tables = [],
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->tables === [];
    }

    /** How many individual differences the whole diff contains. */
    public function count(): int
    {
        $count = 0;

        foreach ($this->tables as $table) {
            $count += $table->count();
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'changes' => $this->count(),
            'tables' => array_map(static fn (TableDiff $diff): array => $diff->toArray(), $this->tables),
        ];
    }
}
