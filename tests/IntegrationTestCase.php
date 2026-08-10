<?php

declare(strict_types=1);

namespace Difflock\Tests;

use Illuminate\Foundation\Application;
use Override;

/**
 * A test case pointed at a real MySQL, MariaDB or PostgreSQL.
 *
 * The unit suite runs on SQLite in memory and needs nothing installed. These tests
 * are the other half of the bargain: Difflock's introspection goes through Laravel's
 * own grammars, and the things that differ between drivers — whether a length is
 * reported, whether `unsigned` is a concept, what a foreign key is called, what the
 * cheap row-count query is — can only be proven against the real servers.
 *
 * They skip themselves when `DIFFLOCK_DB_DRIVER` is unset, so `composer test` on a
 * laptop with no Docker running still passes and still means something.
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $driver = self::driver();

        if ($driver === null) {
            return;
        }

        $app['config']->set('database.default', 'integration');
        $app['config']->set('database.connections.integration', [
            'driver' => $driver,
            'host' => self::env('DIFFLOCK_DB_HOST', '127.0.0.1'),
            'port' => self::env('DIFFLOCK_DB_PORT', $driver === 'pgsql' ? '5432' : '3306'),
            'database' => self::env('DIFFLOCK_DB_DATABASE', 'difflock'),
            'username' => self::env('DIFFLOCK_DB_USERNAME', 'difflock'),
            'password' => self::env('DIFFLOCK_DB_PASSWORD', 'secret'),
            'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
            'prefix' => '',
        ]);
    }

    /** The driver under test, or null when these tests should skip. */
    public static function driver(): ?string
    {
        $driver = self::env('DIFFLOCK_DB_DRIVER', '');

        return $driver === '' ? null : $driver;
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }
}
