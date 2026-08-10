<?php

declare(strict_types=1);

namespace Difflock;

use Difflock\Console\Commands\CheckCommand;
use Difflock\Console\Commands\DiffCommand;
use Difflock\Console\Commands\DifflockCommand;
use Difflock\Console\Commands\DoctorCommand;
use Difflock\Console\Commands\LintCommand;
use Difflock\Console\Commands\MigrateCommand;
use Difflock\Contracts\MigrationAnalyzer;
use Difflock\Contracts\MigrationRule;
use Difflock\Contracts\SchemaDiffer;
use Difflock\Contracts\SchemaInspector;
use Difflock\Database\DatabaseContextFactory;
use Difflock\Diff\SchemaComparator;
use Difflock\Migration\AcceptedFindings;
use Difflock\Migration\IgnoreList;
use Difflock\Migration\MigrationLocator;
use Difflock\Migration\Parser\MigrationParser;
use Difflock\Migration\RuleMigrationAnalyzer;
use Difflock\Migration\Thresholds;
use Difflock\Protection\MigrationGuard;
use Difflock\Protection\ProtectionPolicy;
use Difflock\Risk\RiskLevel;
use Difflock\Schema\Baseline;
use Difflock\Schema\ConnectionSchemaInspector;
use Difflock\Schema\ScopedSchemaInspector;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Override;

final class DifflockServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/difflock.php', 'difflock');

        // Reading a schema is several queries per table, so an inspector memoises
        // what it read for as long as it lives — and it is deliberately bound
        // transient rather than shared, so how long that is never outlives the piece
        // of work that asked for it.
        //
        // Getting this wrong is not a performance bug, it is a correctness one: a
        // shared inspector in a long-running worker, or across two commands in one
        // process, answers the second question with the schema from the first, and a
        // drift check built on a stale reading reports no drift.
        //
        // The scope decorator wraps every inspection, so an ignored table is absent
        // from the diff, from the baseline that gets committed, and from what the
        // rules can see — one decision, applied once.
        $this->app->bind(SchemaInspector::class, fn (Application $app): SchemaInspector => new ScopedSchemaInspector(
            new ConnectionSchemaInspector(
                $app->make(ConnectionResolverInterface::class),
                $this->connection($app),
            ),
            IgnoreList::fromConfig($this->section($app, 'difflock.ignore'))->tables,
        ));

        $this->app->bind(SchemaDiffer::class, SchemaComparator::class);

        $this->app->bind(Thresholds::class, function (Application $app): Thresholds {
            $config = $app->make(Repository::class);

            return new Thresholds(
                $this->integer($config->get('difflock.thresholds.medium_table_rows'), 100_000),
                $this->integer($config->get('difflock.thresholds.large_table_rows'), 1_000_000),
            );
        });

        // The database context is built once per factory and shared by every rule in
        // a run, which is what keeps a hundred migrations from reading the schema a
        // hundred times. Transient for the same reason as the inspector above: the
        // memo should last exactly one analysis.
        $this->app->bind(DatabaseContextFactory::class, fn (Application $app): DatabaseContextFactory => new DatabaseContextFactory(
            $app->make(SchemaInspector::class),
            $app->make(ConnectionResolverInterface::class),
            $app,
            $app->make(Thresholds::class),
            $this->connection($app),
        ));

        $this->app->bind(Baseline::class, function (Application $app): Baseline {
            $config = $app->make(Repository::class);

            return new Baseline(
                $app->make(Filesystem::class),
                $this->baselinePath($app),
                $config->get('difflock.snapshot.defaults') !== false,
                $config->get('difflock.snapshot.comments') !== false,
            );
        });

        // Seeded from configuration here, and open to Difflock::rule() afterwards.
        // The analyzer resolves the registry when it runs rather than when it is
        // built, so a rule registered in another provider's boot() still counts.
        $this->app->singleton(RuleRegistry::class, fn (Application $app): RuleRegistry => new RuleRegistry(
            $this->configuredRules($app),
        ));

        $this->app->bind(MigrationLocator::class, fn (Application $app): MigrationLocator => new MigrationLocator(
            $app->make(Migrator::class),
            $this->migrationPaths($app),
        ));

        $this->app->bind(
            MigrationAnalyzer::class,
            fn (Application $app): MigrationAnalyzer => $this->analyzer($app, $app->make(DatabaseContextFactory::class)),
        );

        $this->app->bind(AcceptedFindings::class, fn (Application $app): AcceptedFindings => new AcceptedFindings(
            $app->make(Filesystem::class),
            $this->acceptedPath($app),
        ));

        $this->app->bind(ProtectionPolicy::class, function (Application $app): ProtectionPolicy {
            $config = $app->make(Repository::class);

            return new ProtectionPolicy(
                $config->get('difflock.protection.enabled') !== false,
                $this->level($config->get('difflock.protection.block_on')),
            );
        });

        $this->app->bind(MigrationGuard::class, fn (Application $app): MigrationGuard => new MigrationGuard(
            $app->make(MigrationAnalyzer::class),
            $app->make(ProtectionPolicy::class),
        ));

        // Checkup and the analyzer it drives are given the *same* context factory, so
        // the schema is read once for the whole run rather than once for drift and
        // again for the rules. Built here rather than resolved twice, because the
        // factory is deliberately transient — its memo must not outlive the run.
        $this->app->bind(Checkup::class, function (Application $app): Checkup {
            $contexts = $app->make(DatabaseContextFactory::class);

            return new Checkup(
                $app->make(SchemaInspector::class),
                $app->make(SchemaDiffer::class),
                $this->analyzer($app, $contexts),
                $app->make(Baseline::class),
                $contexts,
            );
        });

        $this->app->bind(Difflock::class, fn (Application $app): Difflock => new Difflock(
            $app->make(SchemaInspector::class),
            $app->make(SchemaDiffer::class),
            $app->make(MigrationAnalyzer::class),
            $app->make(MigrationGuard::class),
            $app->make(Baseline::class),
            $app->make(RuleRegistry::class),
        ));
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            DifflockCommand::class,
            CheckCommand::class,
            DiffCommand::class,
            DoctorCommand::class,
            LintCommand::class,
            MigrateCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/difflock.php' => $this->app->configPath('difflock.php'),
        ], 'difflock-config');
    }

    /**
     * An analyzer wired to a particular context factory.
     *
     * Taking the factory as an argument rather than resolving it is what lets a
     * caller share one schema reading across everything it drives.
     */
    private function analyzer(Application $app, DatabaseContextFactory $contexts): MigrationAnalyzer
    {
        return new RuleMigrationAnalyzer(
            $app->make(MigrationLocator::class),
            $app->make(MigrationParser::class),
            $app->make(Filesystem::class),
            $contexts,
            $app->make(RuleRegistry::class)->resolve($app),
            IgnoreList::fromConfig($this->section($app, 'difflock.ignore')),
            $app->make(AcceptedFindings::class),
        );
    }

    /**
     * The rule classes named in configuration.
     *
     * A rule that is not a class, or is a class that is not a
     * {@see MigrationRule}, is dropped here rather than at resolve time — a typo in
     * the configured list should cost you that rule, not every Artisan command in
     * the application.
     *
     * @return list<class-string<MigrationRule>|MigrationRule>
     */
    private function configuredRules(Application $app): array
    {
        $rules = [];

        foreach ($this->section($app, 'difflock.rules') as $rule) {
            if ($rule instanceof MigrationRule) {
                $rules[] = $rule;

                continue;
            }

            if (is_string($rule) && is_subclass_of($rule, MigrationRule::class)) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * Where to look for migrations, beyond whatever the migrator was told about.
     *
     * `database/migrations` is included explicitly for the same reason Laravel's own
     * `migrate` command includes it: the migrator's registered paths are the *extra*
     * ones, and an application that never called `loadMigrationsFrom` has none.
     *
     * @return list<string>
     */
    private function migrationPaths(Application $app): array
    {
        $paths = [$app->databasePath('migrations')];

        foreach ($this->section($app, 'difflock.migrations.paths') as $path) {
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private function baselinePath(Application $app): string
    {
        $path = $app->make(Repository::class)->get('difflock.baseline');

        return is_string($path) && $path !== ''
            ? $path
            : $app->databasePath('difflock/schema.json');
    }

    private function acceptedPath(Application $app): string
    {
        $path = $app->make(Repository::class)->get('difflock.accepted');

        return is_string($path) && $path !== ''
            ? $path
            : $app->databasePath('difflock/accepted.json');
    }

    private function connection(Application $app): ?string
    {
        $connection = $app->make(Repository::class)->get('difflock.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * @return array<mixed>
     */
    private function section(Application $app, string $key): array
    {
        $value = $app->make(Repository::class)->get($key);

        return is_array($value) ? $value : [];
    }

    /** A configured risk level, falling back to critical when it names nothing real. */
    private function level(mixed $value): RiskLevel
    {
        return is_string($value)
            ? RiskLevel::tryFrom(strtolower($value)) ?? RiskLevel::Critical
            : RiskLevel::Critical;
    }

    private function integer(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
