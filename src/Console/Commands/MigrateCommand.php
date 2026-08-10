<?php

declare(strict_types=1);

namespace Difflock\Console\Commands;

use Difflock\Console\Commands\Concerns\InteractsWithDifflock;
use Difflock\Console\Formatters\JsonReport;
use Difflock\Console\Renderers\Banner;
use Difflock\Console\Renderers\ReportRenderer;
use Difflock\Console\Renderers\Text;
use Difflock\Protection\GuardDecision;
use Difflock\Protection\MigrationGuard;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * Analyses the pending migrations, and hands over to Laravel's own `migrate` only if
 * they are safe to run.
 *
 * Three promises this command keeps, and they are the reason it exists as a separate
 * command rather than as a hook into `migrate`:
 *
 *   - **`--dry-run` never writes.** It analyses and prints and returns. There is no
 *     branch in it that reaches the database with anything but a read.
 *   - **A blocked run does not migrate.** The call to Laravel's migrator is on the
 *     other side of the decision, not wrapped around it.
 *   - **Laravel's own safeguards are untouched.** `--force` is passed straight
 *     through and means what it has always meant — skip the production
 *     confirmation. Overriding *Difflock's* block is a different option with a
 *     different name, so nobody bypasses one while intending the other.
 */
final class MigrateCommand extends Command
{
    use InteractsWithDifflock;

    protected $signature = 'difflock:migrate
        {--dry-run : Analyse the pending migrations and print what they would do, without running anything}
        {--allow-risky : Run the migrations even though Difflock would block them}
        {--force : Passed through to migrate: run without the production confirmation prompt}
        {--database= : Passed through to migrate: the connection to migrate}
        {--path=* : Analyse and migrate only these directories}
        {--realpath : Treat the given paths as absolute rather than relative to the application}
        {--format=text : text for a person, json for anything else}';

    protected $description = 'Analyse the pending migrations and run them only if they are safe';

    protected function configure(): void
    {
        parent::configure();

        $this->setHelp(<<<'HELP'
        Analyses every pending migration first. If nothing is at or above the
        configured block level, it hands over to <info>php artisan migrate</info> unchanged. If
        something is, it prints why and stops — and nothing has touched the database.

        <info>--dry-run</info> analyses and prints and stops there, whatever it finds. It never
        writes: there is no path through this command that reaches the database with
        anything but a read.

        <info>--allow-risky</info> runs the migrations anyway. It is deliberately not spelled
        <info>--force</info>, which is passed straight through to Laravel and still means what
        it always meant. Bypassing Difflock and skipping the production confirmation
        are different decisions and should not share a flag.

        The block level is <info>difflock.protection.block_on</info>, and defaults to <info>critical</info>.
        Set <info>difflock.protection.enabled</info> to false to have this command analyse and
        report but never block.

        Exit codes: 0 the migrations ran or the dry run finished, 1 the run was
        blocked, 2 a configuration or runtime error. When migrations do run, the exit
        code is whatever <info>migrate</info> returned.
        HELP);
    }

    public function handle(MigrationGuard $guard, Repository $config, ReportRenderer $renderer): int
    {
        if (! $this->enabled($config)) {
            return self::INVALID;
        }

        $policy = $this->option('allow-risky') === true ? $guard->policy()->disabled() : null;

        try {
            $decision = $guard->inspect($this->paths(), $policy);
        } catch (Throwable $exception) {
            $this->components->error('Difflock could not analyse the pending migrations: '.$exception->getMessage());

            return self::INVALID;
        }

        if ($this->wantsJson()) {
            return $this->json($decision);
        }

        Banner::render($this->output, 'Difflock  ·  Migration Guard');

        $renderer->render($this->output, $decision->report);

        if ($this->option('dry-run') === true) {
            $this->note('Dry run. Nothing was written to the database.');

            return self::SUCCESS;
        }

        if ($decision->blocked) {
            $this->output->writeln('  <fg=red;options=bold>Migration blocked.</>');
            $this->note(
                'Review the findings above. Re-run with --allow-risky once you have decided they are '
                    .'acceptable, or fix the migrations and try again.',
            );

            return self::FAILURE;
        }

        return $this->migrate($decision);
    }

    /**
     * Hand over to Laravel's own migrator.
     *
     * Only the options the user actually passed are forwarded, so `migrate` behaves
     * exactly as it would have been asked to directly — including prompting for
     * confirmation in production when `--force` was not given.
     */
    private function migrate(GuardDecision $decision): int
    {
        if (! $decision->enforced) {
            $this->note('Difflock protection is switched off, so nothing here was going to block.');
        }

        return $this->call('migrate', $this->migrateOptions());
    }

    /**
     * @return array<string, mixed>
     */
    private function migrateOptions(): array
    {
        $options = [];

        if ($this->option('force') === true) {
            $options['--force'] = true;
        }

        $database = $this->option('database');

        if (is_string($database) && $database !== '') {
            $options['--database'] = $database;
        }

        $paths = $this->option('path');

        if (is_array($paths) && $paths !== []) {
            $options['--path'] = $paths;

            if ($this->option('realpath') === true) {
                $options['--realpath'] = true;
            }
        }

        return $options;
    }

    /**
     * The JSON path, which runs the migrator silently.
     *
     * `migrate` writes its own progress in prose, and a document with a table of
     * migration names printed through the middle of it is not JSON. Silencing it is
     * the price of the promise that `--format=json` emits one parseable document and
     * nothing else; the migrator's exit code still comes back and is still returned.
     */
    private function json(GuardDecision $decision): int
    {
        $dryRun = $this->option('dry-run') === true;
        $blocked = ! $dryRun && $decision->blocked;

        $exit = self::SUCCESS;
        $migrated = false;

        if ($blocked) {
            $exit = self::FAILURE;
        } elseif (! $dryRun) {
            $exit = $this->callSilent('migrate', $this->migrateOptions());
            $migrated = true;
        }

        $this->writeJson([
            'difflock' => JsonReport::VERSION,
            'status' => $blocked ? 'failed' : 'passed',
            'dry_run' => $dryRun,
            'migrated' => $migrated,
        ] + $decision->toArray());

        return $exit;
    }

    private function note(string $message): void
    {
        foreach (Text::wrap($message, '  ') as $line) {
            $this->output->writeln('<fg=gray>'.$line.'</>');
        }

        $this->output->writeln('');
    }
}
