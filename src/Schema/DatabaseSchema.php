<?php

declare(strict_types=1);

namespace Difflock\Schema;

/**
 * A whole database's structure at one instant, normalised and deterministic.
 *
 * Two inspections of equivalent schemas produce equal objects: tables are keyed and
 * sorted by name, and so is everything inside them. That property is what makes the
 * diff engine trustworthy — a difference it reports is a difference in the schema,
 * never a difference in the order a driver listed things.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class DatabaseSchema
{
    /** @var array<string, Table> */
    public array $tables;

    /**
     * @param  list<Table>  $tables
     * @param  string|null  $driver  The driver the schema was read from, when it was read from one.
     * @param  string|null  $connection  The connection name, for display.
     */
    public function __construct(
        array $tables = [],
        public ?string $driver = null,
        public ?string $connection = null,
    ) {
        $keyed = [];

        foreach ($tables as $table) {
            $keyed[$table->name] = $table;
        }

        ksort($keyed);

        $this->tables = $keyed;
    }

    public function table(string $name): ?Table
    {
        return $this->tables[$name] ?? null;
    }

    public function hasTable(string $name): bool
    {
        return isset($this->tables[$name]);
    }

    /** @return list<string> */
    public function tableNames(): array
    {
        return array_keys($this->tables);
    }

    /**
     * The same schema with the named tables left out.
     *
     * Used for the ignore list, and for keeping the framework's own bookkeeping
     * tables out of a drift report where the application asks for that.
     *
     * @param  list<string>  $names
     */
    public function without(array $names): self
    {
        $kept = [];

        foreach ($this->tables as $name => $table) {
            if (! in_array($name, $names, true)) {
                $kept[] = $table;
            }
        }

        return new self($kept, $this->driver, $this->connection);
    }

    /**
     * The same schema with column defaults and comments dropped.
     *
     * Used when recording a baseline that will be committed. Both fields are kept by
     * default — they are part of the schema and worth diffing — and an application
     * that would rather not have them in git can say so.
     */
    public function redacted(bool $keepDefaults, bool $keepComments): self
    {
        if ($keepDefaults && $keepComments) {
            return $this;
        }

        return new self(
            array_values(array_map(
                static fn (Table $table): Table => $table->redacted($keepDefaults, $keepComments),
                $this->tables,
            )),
            $this->driver,
            $this->connection,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'connection' => $this->connection,
            'tables' => array_map(static fn (Table $table): array => $table->toArray(), $this->tables),
        ];
    }
}
