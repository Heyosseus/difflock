<?php

declare(strict_types=1);

use Difflock\Contracts\TableStatistics;
use Difflock\Database\FixedTableStatistics;
use Difflock\Database\NullTableStatistics;
use Difflock\Migration\DatabaseContext;
use Difflock\Migration\MigrationContext;
use Difflock\Migration\Parser\MigrationParser;
use Difflock\Migration\Parser\ParsedMigration;
use Difflock\Migration\Thresholds;
use Difflock\Schema\Column;
use Difflock\Schema\DatabaseSchema;
use Difflock\Schema\Table;
use Difflock\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * Parse a migration body, wrapped in just enough class to be a migration.
 *
 * Tests are written against the `up()` body rather than a whole file, because that
 * is the part every one of them is actually about.
 */
function parseUp(string $body, string $down = '', string $name = '2026_08_10_120000_test'): ParsedMigration
{
    $downMethod = $down === '' ? '' : "public function down(): void\n{\n{$down}\n}";

    $source = <<<PHP
    <?php

    use Illuminate\\Database\\Migrations\\Migration;
    use Illuminate\\Database\\Schema\\Blueprint;
    use Illuminate\\Support\\Facades\\Schema;

    return new class extends Migration
    {
        public function up(): void
        {
    {$body}
        }

        {$downMethod}
    };
    PHP;

    return (new MigrationParser)->parse($source, $name, $name.'.php');
}

/**
 * A context over the first schema statement of a parsed migration, with whatever
 * live schema and table sizes the test wants the rules to see.
 *
 * @param  list<Table>  $tables
 * @param  array<string, int>  $rows
 */
function contextFor(
    ParsedMigration $migration,
    array $tables = [],
    array $rows = [],
    int $statement = 0,
    bool $available = true,
    ?Thresholds $thresholds = null,
): MigrationContext {
    return new MigrationContext(
        $migration,
        $migration->statements[$statement],
        new DatabaseContext(
            schema: new DatabaseSchema($tables, 'sqlite', 'testing'),
            statistics: $rows === [] ? new NullTableStatistics : new FixedTableStatistics($rows),
            thresholds: $thresholds ?? new Thresholds,
            environment: 'testing',
            available: $available,
        ),
    );
}

/**
 * A context whose database reports a particular driver — or none at all.
 *
 * Engine-aware rules need this: `unindexed-foreign-key` must say nothing on MySQL,
 * something specific on PostgreSQL, and something hedged when it cannot tell.
 *
 * @param  list<Table>  $tables
 * @param  array<string, int>  $rows
 */
function driverContext(string $body, ?string $driver, array $tables = [], array $rows = []): MigrationContext
{
    $parsed = parseUp($body, 'x');

    return new MigrationContext(
        $parsed,
        $parsed->statements[0],
        new DatabaseContext(
            schema: new DatabaseSchema($tables, $driver, 'main'),
            statistics: new FixedTableStatistics($rows),
        ),
    );
}

/** A context built from one migration body in one call, which is what most rule tests want. */
function ruleContext(string $body, array $tables = [], array $rows = [], string $down = 'x'): MigrationContext
{
    return contextFor(parseUp($body, $down), $tables, $rows);
}

/** A column, without naming the six fields a given test does not care about. */
function column(
    string $name,
    string $type = 'varchar',
    string $definition = 'varchar(255)',
    bool $nullable = false,
    ?string $default = null,
    ?int $length = 255,
): Column {
    return new Column(
        name: $name,
        type: $type,
        definition: $definition,
        nullable: $nullable,
        default: $default,
        length: $length,
    );
}

/**
 * Run an Artisan command and give back its exit code and everything it wrote.
 *
 * The buffer decides whether it is decorated, so Symfony is the thing stripping the
 * ANSI rather than the assertion — which is the only way to prove that `--no-ansi`
 * output is genuinely readable and that `--format=json` is genuinely clean.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
function runCommand(string $command, array $parameters = [], bool $decorated = false): array
{
    $output = new Symfony\Component\Console\Output\BufferedOutput(
        Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL,
        $decorated,
    );

    $exit = app(Illuminate\Contracts\Console\Kernel::class)->call($command, $parameters, $output);

    return [$exit, $output->fetch()];
}

/** A directory of migration fixtures, written the way an application would write them. */
function fixtures(string $directory = 'migrations'): string
{
    return __DIR__.'/fixtures/'.$directory;
}

/** Statistics that answer for the given tables and null for everything else. */
function sizes(array $rows, bool $approximate = false): TableStatistics
{
    return new FixedTableStatistics($rows, [], $approximate);
}
