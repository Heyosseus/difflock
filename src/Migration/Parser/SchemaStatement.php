<?php

declare(strict_types=1);

namespace Difflock\Migration\Parser;

/**
 * One `Schema::` call in a migration, with whatever its closure did.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class SchemaStatement
{
    /**
     * @param  string  $method  `create`, `table`, `drop`, `dropIfExists`, `rename`.
     * @param  string|null  $table  Null when the table name was not a literal the parser could read.
     * @param  list<Operation>  $operations
     * @param  bool  $conditional  Whether the call sits inside an `if`, a loop, or a `try`.
     */
    public function __construct(
        public string $method,
        public ?string $table,
        public array $operations = [],
        public int $line = 0,
        public bool $conditional = false,
        public ?string $connection = null,
    ) {}

    /** Whether this call creates the table, so everything in it is new rather than altered. */
    public function isCreate(): bool
    {
        return $this->method === 'create' || $this->method === 'createIfNotExists';
    }

    /** Whether this call removes the table outright. */
    public function isDrop(): bool
    {
        return $this->method === 'drop' || $this->method === 'dropIfExists';
    }

    /** Whether this call alters a table that is expected to already exist. */
    public function isAlter(): bool
    {
        return $this->method === 'table';
    }
}
