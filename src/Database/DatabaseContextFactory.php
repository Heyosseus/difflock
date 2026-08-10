<?php

declare(strict_types=1);

namespace Difflock\Database;

use Difflock\Contracts\SchemaInspector;
use Difflock\Migration\DatabaseContext;
use Difflock\Migration\Thresholds;
use Difflock\Schema\DatabaseSchema;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use PDO;
use Throwable;

/**
 * Assembles the one {@see DatabaseContext} every rule in a run shares.
 *
 * Built once per analysis and handed to every rule, so a hundred migrations against
 * forty tables read the schema once and the size metadata once. Rules cannot reach
 * the database themselves, which is what makes that guarantee hold.
 *
 * A database that cannot be reached is not an error here. Linting a migration in CI
 * with no database attached is a legitimate thing to want, and it still catches
 * every rule that reads only the source — the drop, the rename, the cascade. The
 * context is simply marked unavailable, every size comes back unknown, and the
 * report says so at the top rather than quietly grading on a curve.
 */
final class DatabaseContextFactory
{
    private ?DatabaseContext $context = null;

    public function __construct(
        private readonly SchemaInspector $inspector,
        private readonly ConnectionResolverInterface $connections,
        private readonly Application $app,
        private readonly Thresholds $thresholds,
        private readonly ?string $connection = null,
    ) {}

    public function make(): DatabaseContext
    {
        return $this->context ??= $this->build();
    }

    private function build(): DatabaseContext
    {
        try {
            $schema = $this->inspector->inspect($this->connection);
        } catch (Throwable) {
            return new DatabaseContext(
                schema: new DatabaseSchema,
                statistics: new NullTableStatistics,
                thresholds: $this->thresholds,
                environment: $this->environment(),
                available: false,
            );
        }

        return new DatabaseContext(
            schema: $schema,
            statistics: new ConnectionTableStatistics($this->connections, $this->connection),
            thresholds: $this->thresholds,
            environment: $this->environment(),
            version: $this->version(),
            available: true,
        );
    }

    private function environment(): string
    {
        $environment = $this->app->environment();

        return is_string($environment) ? $environment : 'unknown';
    }

    private function version(): ?string
    {
        try {
            $connection = $this->connections->connection($this->connection);

            if (! $connection instanceof Connection) {
                return null;
            }

            $version = $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            return null;
        }

        return is_scalar($version) ? (string) $version : null;
    }
}
