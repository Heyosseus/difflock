<?php

declare(strict_types=1);

namespace Difflock\Schema;

/**
 * One foreign key, normalised across drivers.
 *
 * `onDelete` and `onUpdate` are kept verbatim and lower-cased rather than mapped to
 * an enum: the referential actions a driver accepts differ, and a value Difflock
 * does not recognise should still be shown to the reader rather than dropped.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class ForeignKey
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>  $foreignColumns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public string $foreignTable,
        public array $foreignColumns,
        public ?string $onDelete = null,
        public ?string $onUpdate = null,
    ) {}

    public function render(): string
    {
        $rendered = implode(', ', $this->columns)
            .' → '.$this->foreignTable.'('.implode(', ', $this->foreignColumns).')';

        if ($this->onDelete !== null) {
            $rendered .= ' ON DELETE '.strtoupper($this->onDelete);
        }

        if ($this->onUpdate !== null) {
            $rendered .= ' ON UPDATE '.strtoupper($this->onUpdate);
        }

        return $rendered;
    }

    /**
     * @return array<string, scalar>
     */
    public function comparable(): array
    {
        $fields = [
            'columns' => implode(',', $this->columns),
            'foreign_table' => $this->foreignTable,
            'foreign_columns' => implode(',', $this->foreignColumns),
        ];

        // A driver that does not report a referential action is not saying the
        // action is absent, so an unreported one is left out of the comparison
        // rather than compared against null and reported as a change.
        if ($this->onDelete !== null) {
            $fields['on_delete'] = $this->onDelete;
        }

        if ($this->onUpdate !== null) {
            $fields['on_update'] = $this->onUpdate;
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'columns' => $this->columns,
            'foreign_table' => $this->foreignTable,
            'foreign_columns' => $this->foreignColumns,
            'on_delete' => $this->onDelete,
            'on_update' => $this->onUpdate,
        ];
    }
}
