<?php

declare(strict_types=1);

namespace Difflock\Diff;

use Difflock\Schema\Index;

/**
 * One index added, removed, or altered.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class IndexDiff
{
    /**
     * @param  array<string, array{from: scalar|null, to: scalar|null}>  $changes
     */
    public function __construct(
        public string $name,
        public ChangeType $type,
        public ?Index $from = null,
        public ?Index $to = null,
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
