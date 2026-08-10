<?php

declare(strict_types=1);

namespace Difflock\Migration;

use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * Finds migration files, and works out which of them have not run.
 *
 * "Pending" is asked of Laravel's own migration repository rather than inferred, so
 * Difflock agrees with `php artisan migrate:status` by construction. Where the
 * repository table does not exist — a fresh checkout, a CI job with an empty
 * database — *everything* is pending, which is both true and the safe direction: a
 * migration Difflock cannot prove has run is one it should still be checking.
 */
final class MigrationLocator
{
    /** @var list<MigrationFile>|null */
    private ?array $files = null;

    /**
     * @param  list<string>  $paths  Where to look, in addition to whatever the Migrator knows about.
     */
    public function __construct(
        private readonly Migrator $migrator,
        private readonly array $paths = [],
    ) {}

    /**
     * @param  list<string>  $paths  Look only here, ignoring the configured paths entirely.
     * @return list<MigrationFile>
     */
    public function locate(MigrationScope $scope, array $paths = []): array
    {
        $files = $paths === [] ? $this->all() : $this->read($paths);

        if ($scope === MigrationScope::All) {
            return $files;
        }

        return array_values(array_filter($files, static fn (MigrationFile $file): bool => $file->pending));
    }

    /**
     * @return list<MigrationFile>
     */
    private function all(): array
    {
        return $this->files ??= $this->read($this->searchPaths());
    }

    /**
     * @param  list<string>  $paths
     * @return list<MigrationFile>
     */
    private function read(array $paths): array
    {
        $ran = $this->ran();

        $files = [];

        foreach ($this->migrator->getMigrationFiles($paths) as $name => $path) {
            $files[] = new MigrationFile(
                name: (string) $name,
                path: $path,
                pending: ! in_array((string) $name, $ran, true),
            );
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function searchPaths(): array
    {
        $paths = array_merge($this->migrator->paths(), $this->paths);

        return array_values(array_unique(array_filter($paths, static fn (string $path): bool => $path !== '')));
    }

    /**
     * The migrations the repository says have run.
     *
     * A repository that is missing, or a database that cannot be reached, means no
     * migration can be proven to have run — so none is treated as having done.
     *
     * @return list<string>
     */
    private function ran(): array
    {
        try {
            if (! $this->migrator->repositoryExists()) {
                return [];
            }

            $ran = [];

            foreach ($this->migrator->getRepository()->getRan() as $migration) {
                if (is_string($migration)) {
                    $ran[] = $migration;
                }
            }

            return $ran;
        } catch (Throwable) {
            return [];
        }
    }
}
