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

    protected function baselinePath(): string
    {
        return sys_get_temp_dir().'/difflock-tests/'.static::class.'/schema.json';
    }

    protected function tearDown(): void
    {
        $directory = dirname($this->baselinePath());

        if (is_file($this->baselinePath())) {
            unlink($this->baselinePath());
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }

        parent::tearDown();
    }
}
