<?php

declare(strict_types=1);

namespace Difflock\Database;

use Difflock\Contracts\TableStatistics;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use RuntimeException;
use Throwable;

/**
 * How big each table is, read from database metadata in one query.
 *
 * Every driver here is asked its own cheap question and nothing else:
 *
 *   - **MySQL/MariaDB** — `information_schema.tables`, which carries the InnoDB row
 *     estimate and the data and index lengths. The row count is an estimate, and can
 *     be off by a wide margin on a table with heavy churn.
 *   - **PostgreSQL** — `pg_class.reltuples`, the planner's own estimate, which is
 *     `-1` on a table nothing has ever analysed and is reported as unknown rather
 *     than as zero.
 *   - **SQLite** — an actual `COUNT(*)`, per table and only when asked. SQLite keeps
 *     no row estimate, and a SQLite database is a development one; counting it is
 *     both exact and cheap enough. Size in bytes is not available.
 *
 * Anything else answers null throughout, which the rules read as "unknown".
 *
 * The whole thing is one query, run at most once, and never `SELECT *`. Difflock is
 * meant to be safe to point at production, and a monitoring tool that scans tables
 * to find out how big they are has become the problem it was installed to prevent.
 */
final class ConnectionTableStatistics implements TableStatistics
{
    /** @var array<string, int|null>|null */
    private ?array $rows = null;

    /** @var array<string, int|null> */
    private array $bytes = [];

    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly ?string $connection = null,
    ) {}

    public function rows(string $table): ?int
    {
        $this->load();

        return $this->rows[$table] ?? $this->count($table);
    }

    public function bytes(string $table): ?int
    {
        $this->load();

        return $this->bytes[$table] ?? null;
    }

    public function approximate(): bool
    {
        return $this->driver() !== 'sqlite';
    }

    private function driver(): string
    {
        try {
            return $this->connection()->getDriverName();
        } catch (Throwable) {
            return '';
        }
    }

    private function connection(): Connection
    {
        $connection = $this->connections->connection($this->connection);

        // The resolver's interface is wider than the class every driver actually
        // returns, and the metadata queries below need a real connection.
        return $connection instanceof Connection
            ? $connection
            : throw new RuntimeException('Difflock needs a database connection it can query.');
    }

    private function load(): void
    {
        if ($this->rows !== null) {
            return;
        }

        $this->rows = [];

        try {
            match ($this->driver()) {
                'mysql', 'mariadb' => $this->loadMysql(),
                'pgsql' => $this->loadPostgres(),
                default => null,
            };
        } catch (Throwable) {
            // A role without access to the metadata views is a perfectly ordinary
            // production setup. Unknown sizes make the rules more cautious, not less,
            // so this degrades rather than fails.
            $this->rows = [];
            $this->bytes = [];
        }
    }

    private function loadMysql(): void
    {
        $sql = 'select table_name as name, table_rows as rows_estimate, '
            .'(coalesce(data_length, 0) + coalesce(index_length, 0)) as size_bytes '
            .'from information_schema.tables where table_schema = database()';

        foreach ($this->connection()->select($sql) as $row) {
            $this->record($row, 'rows_estimate', 'size_bytes');
        }
    }

    private function loadPostgres(): void
    {
        $sql = 'select c.relname as name, c.reltuples as rows_estimate, '
            .'pg_total_relation_size(c.oid) as size_bytes '
            .'from pg_class c join pg_namespace n on n.oid = c.relnamespace '
            ."where c.relkind = 'r' and n.nspname not in ('pg_catalog', 'information_schema')";

        foreach ($this->connection()->select($sql) as $row) {
            $this->record($row, 'rows_estimate', 'size_bytes');
        }
    }

    /**
     * PostgreSQL reports `reltuples = -1` for a table nothing has analysed yet. That
     * is the server saying "I do not know", and it must not be rounded to zero.
     */
    private function record(mixed $row, string $rowsKey, string $bytesKey): void
    {
        $values = (array) $row;
        $name = $values['name'] ?? null;

        if (! is_scalar($name)) {
            return;
        }

        $rows = $values[$rowsKey] ?? null;
        $bytes = $values[$bytesKey] ?? null;

        $this->rows[(string) $name] = is_numeric($rows) && (int) $rows >= 0 ? (int) $rows : null;
        $this->bytes[(string) $name] = is_numeric($bytes) && (int) $bytes >= 0 ? (int) $bytes : null;
    }

    /**
     * SQLite's exact count, asked only for the tables a rule actually wants.
     */
    private function count(string $table): ?int
    {
        if ($this->driver() !== 'sqlite') {
            return null;
        }

        try {
            $connection = $this->connection();

            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                return null;
            }

            $count = $connection->table($table)->count();
        } catch (Throwable) {
            return null;
        }

        return $this->rows[$table] = $count;
    }
}
