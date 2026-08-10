<?php

declare(strict_types=1);

namespace Difflock\Database;

use Difflock\Contracts\IndexStatistics;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use RuntimeException;
use Throwable;

/**
 * Index read counts, taken from whatever the engine already keeps.
 *
 *   - **PostgreSQL** — `pg_stat_user_indexes.idx_scan`, the number of index scans
 *     the planner has initiated. The window comes from `pg_stat_database.stats_reset`.
 *   - **MySQL/MariaDB** — `performance_schema.table_io_waits_summary_by_index_usage`,
 *     whose `COUNT_STAR` counts I/O operations against each index. Available only
 *     when performance_schema is enabled, which on many managed instances it is not.
 *   - **SQLite** — nothing. SQLite keeps no such counters, and this says so rather
 *     than counting zero.
 *
 * One query, run at most once, and never against user tables — only against the
 * statistics views the engine maintains for itself.
 *
 * Everything here is best-effort by design. A role without access to the statistics
 * views is an ordinary production setup, and the answer is then "unknown", which
 * makes the rules more cautious rather than less.
 */
final class ConnectionIndexStatistics implements IndexStatistics
{
    /** @var array<string, int>|null */
    private ?array $scans = null;

    private ?int $days = null;

    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly ?string $connection = null,
    ) {}

    public function scans(string $table, string $index): ?int
    {
        $this->load();

        return $this->scans[$this->key($table, $index)] ?? null;
    }

    public function observedDays(): ?int
    {
        $this->load();

        return $this->days;
    }

    private function load(): void
    {
        if ($this->scans !== null) {
            return;
        }

        $this->scans = [];

        try {
            match ($this->driver()) {
                'pgsql' => $this->loadPostgres(),
                'mysql', 'mariadb' => $this->loadMysql(),
                default => null,
            };
        } catch (Throwable) {
            // No access to the statistics views, or the schema does not have them.
            // Unknown is a legitimate answer and the rules are built for it.
            $this->scans = [];
            $this->days = null;
        }
    }

    private function loadPostgres(): void
    {
        $sql = 'select relname as table_name, indexrelname as index_name, idx_scan as scans '
            .'from pg_stat_user_indexes';

        foreach ($this->connection()->select($sql) as $row) {
            $this->record($row);
        }

        $reset = $this->connection()->select(
            'select extract(epoch from (now() - stats_reset)) / 86400 as days '
                .'from pg_stat_database where datname = current_database()',
        );

        $days = ((array) ($reset[0] ?? []))['days'] ?? null;

        $this->days = is_numeric($days) && (int) $days >= 0 ? (int) $days : null;
    }

    private function loadMysql(): void
    {
        $sql = 'select object_name as table_name, index_name, count_star as scans '
            .'from performance_schema.table_io_waits_summary_by_index_usage '
            .'where object_schema = database() and index_name is not null';

        foreach ($this->connection()->select($sql) as $row) {
            $this->record($row);
        }

        // MySQL's performance_schema counters reset when the server restarts, and
        // uptime is the closest thing to a window it will give.
        $uptime = $this->connection()->select("show global status like 'Uptime'");
        $value = ((array) ($uptime[0] ?? []))['Value'] ?? null;

        $this->days = is_numeric($value) ? (int) ((int) $value / 86400) : null;
    }

    private function record(mixed $row): void
    {
        $values = (array) $row;

        $table = $values['table_name'] ?? null;
        $index = $values['index_name'] ?? null;
        $scans = $values['scans'] ?? null;

        if (! is_scalar($table) || ! is_scalar($index) || ! is_numeric($scans)) {
            return;
        }

        $this->scans[$this->key((string) $table, (string) $index)] = (int) $scans;
    }

    private function key(string $table, string $index): string
    {
        return strtolower($table)."\0".strtolower($index);
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

        return $connection instanceof Connection
            ? $connection
            : throw new RuntimeException('Difflock needs a database connection it can query.');
    }
}
