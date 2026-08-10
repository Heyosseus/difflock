<?php

declare(strict_types=1);

namespace Difflock\Schema;

/**
 * One index, normalised across drivers.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class Index
{
    /**
     * @param  list<string>  $columns  In index order, which is significant: (a, b) is not (b, a).
     * @param  string|null  $type  The driver's own access method, when it reports one: `btree`, `gin`.
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique = false,
        public bool $primary = false,
        public ?string $type = null,
    ) {}

    public function render(): string
    {
        $kind = $this->primary ? 'PRIMARY' : ($this->unique ? 'UNIQUE' : 'INDEX');

        return $kind.' ('.implode(', ', $this->columns).')';
    }

    /**
     * @return array<string, scalar>
     */
    public function comparable(): array
    {
        return [
            'columns' => implode(',', $this->columns),
            'unique' => $this->unique,
            'primary' => $this->primary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'columns' => $this->columns,
            'unique' => $this->unique,
            'primary' => $this->primary,
            'type' => $this->type,
        ];
    }
}
