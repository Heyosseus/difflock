<?php

declare(strict_types=1);

namespace Difflock\Schema;

/**
 * One column, normalised across drivers.
 *
 * Every field is nullable where the driver may not report it. A null `length` on
 * PostgreSQL means "this type has no length", and a null `unsigned` on SQLite means
 * "SQLite has no such concept" — neither is a value Difflock invents to make the
 * shape uniform. The diff engine treats null as "not comparable" for exactly that
 * reason, so a column is never reported as changed because two drivers describe the
 * same thing with different amounts of detail.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class Column
{
    /**
     * @param  string  $type  The normalised type name, lower-cased: `varchar`, `int`, `jsonb`.
     * @param  string  $definition  The driver's own full type string, kept verbatim for display.
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $definition,
        public bool $nullable,
        public ?string $default = null,
        public bool $autoIncrement = false,
        public ?bool $unsigned = null,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public ?string $comment = null,
    ) {}

    /**
     * The same column with the fields that carry free text dropped.
     *
     * A default and a comment are the only two places in a schema where somebody's
     * own words end up — an internal URL in a default, a note to a colleague in a
     * comment — so they are the only two a snapshot can be asked to leave out.
     *
     * Dropping a default costs something real: {@see self::comparable()} leaves out
     * fields that are null, so a snapshot recorded without defaults no longer
     * notices a default changing. That is the trade, and the configuration says so.
     */
    public function redacted(bool $keepDefault, bool $keepComment): self
    {
        if ($keepDefault && $keepComment) {
            return $this;
        }

        return new self(
            name: $this->name,
            type: $this->type,
            definition: $this->definition,
            nullable: $this->nullable,
            default: $keepDefault ? $this->default : null,
            autoIncrement: $this->autoIncrement,
            unsigned: $this->unsigned,
            length: $this->length,
            precision: $this->precision,
            scale: $this->scale,
            comment: $keepComment ? $this->comment : null,
        );
    }

    /**
     * The column as it reads in a diff: `VARCHAR(255) NULL DEFAULT 'x'`.
     */
    public function render(): string
    {
        $parts = [strtoupper($this->definition)];

        $parts[] = $this->nullable ? 'NULL' : 'NOT NULL';

        if ($this->default !== null) {
            $parts[] = 'DEFAULT '.$this->default;
        }

        if ($this->autoIncrement) {
            $parts[] = 'AUTO_INCREMENT';
        }

        return implode(' ', $parts);
    }

    /**
     * The fields two columns are compared on, with the ones a driver did not report
     * left out entirely.
     *
     * @return array<string, scalar>
     */
    public function comparable(): array
    {
        $fields = [
            'type' => $this->type,
            'definition' => strtolower($this->definition),
            'nullable' => $this->nullable,
            'auto_increment' => $this->autoIncrement,
        ];

        foreach (['default' => $this->default, 'unsigned' => $this->unsigned, 'length' => $this->length,
            'precision' => $this->precision, 'scale' => $this->scale] as $key => $value) {
            if ($value !== null) {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'definition' => $this->definition,
            'nullable' => $this->nullable,
            'default' => $this->default,
            'auto_increment' => $this->autoIncrement,
            'unsigned' => $this->unsigned,
            'length' => $this->length,
            'precision' => $this->precision,
            'scale' => $this->scale,
            'comment' => $this->comment,
        ];
    }
}
