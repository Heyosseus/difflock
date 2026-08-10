<?php

declare(strict_types=1);

namespace Difflock\Diff;

use Difflock\Schema\Column;

/**
 * One column added, removed, or altered.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class ColumnDiff
{
    /**
     * @param  array<string, array{from: scalar|null, to: scalar|null}>  $changes
     *                                                                             Which fields differ, present only when the change is {@see ChangeType::Changed}.
     */
    public function __construct(
        public string $name,
        public ChangeType $type,
        public ?Column $from = null,
        public ?Column $to = null,
        public array $changes = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'change' => $this->type->value,
            'from' => $this->from?->toArray(),
            'to' => $this->to?->toArray(),
            'changes' => $this->changes,
        ];
    }
}
