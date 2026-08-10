<?php

declare(strict_types=1);

namespace Difflock\Tests;

use Difflock\DifflockServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [DifflockServiceProvider::class];
    }

    /**
     * The suite runs against SQLite in memory.
     *
     * Difflock's introspection goes through Laravel's own schema builder, so what it
     * reads is driver-independent by construction, and the analysis it performs on
     * top is entirely in PHP. Requiring a MySQL and a PostgreSQL to run the unit
     * suite would buy nothing and cost every contributor a Docker daemon; the CI
     * matrix proves the drivers.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('difflock.baseline', $this->baselinePath());
        $app['config']->set('difflock.migrations.paths', []);
    }

    /**
     * A baseline file of this test class's own, outside the project.
     *
     * The class name is flattened rather than used as a path. Pest's generated test
     * classes are namespaced, and a backslash is a directory separator on Windows and
     * an ordinary filename character everywhere else — so the same expression makes
     * a tree on one OS and one oddly-named directory on the others.
     */
    protected function baselinePath(): string
    {
        return sys_get_temp_dir().'/difflock-tests/'.str_replace('\\', '.', static::class).'/schema.json';
    }

    protected function tearDown(): void
    {
        $path = $this->baselinePath();
        $directory = dirname($path);

        if (is_file($path)) {
            unlink($path);
        }

        // Only when it is actually empty: rmdir on a directory that is not raises a
        // warning, and the suite runs with failOnWarning, so a stray file here would
        // fail an unrelated test rather than the one that left it behind.
        if (is_dir($directory) && scandir($directory) === ['.', '..']) {
            rmdir($directory);
        }

        parent::tearDown();
    }
}
