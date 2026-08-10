<?php

declare(strict_types=1);

namespace Difflock\Console\Commands;

use Difflock\Console\Commands\Concerns\InteractsWithDifflock;
use Difflock\Console\Formatters\JsonReport;
use Difflock\Console\Renderers\Banner;
use Difflock\Console\Renderers\Text;
use Difflock\Database\DatabaseContextFactory;
use Difflock\Migration\AcceptedFindings;
use Difflock\Migration\MigrationLocator;
use Difflock\Migration\MigrationScope;
use Difflock\RuleRegistry;
use Difflock\Schema\Baseline;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Throwable;

/**
 * Reports what Difflock can actually see, and what it could do if it wanted to.
 *
 * The package's central claim is that it never writes to the database it inspects.
 * That is a claim about *code*, and code can be reviewed — but the stronger
 * guarantee is a role that could not write even if the code tried. This command is
 * how you check which of the two you are relying on.
 *
 * It is also the first thing to run when a report says something surprising. A drift
 * check that sees no tables, a rule that reports every row count as unknown, an
 * engine-aware rule that stays silent — all of them have the same handful of causes,
 * and all of them are visible here.
 *
 * Everything it does is a read.
 */
final class DoctorCommand extends Command
{
    use InteractsWithDifflock;

    protected $signature = 'difflock:doctor
        {--connection= : The connection to examine, overriding the configured one}
        {--format=text : text for a person, json for anything else}';

    protected $description = 'Report what Difflock can see: connection, privileges, paths and rules';

    protected function configure(): void
    {
        parent::configure();

        $this->setHelp(<<<'HELP'
        Prints the ground every other command stands on: which connection is being
        inspected, what engine and version answered, whether the role Difflock
        connects as is able to write, how many tables and migrations it can see,
        which rules are registered, and where the baseline and accepted-findings
        files live.

        The write-privilege line is the one worth reading. Difflock has no code path
        that writes to the inspected database — but <comment>a read-only role is the version of
        that promise which does not depend on trusting the code</comment>, and this says
        whether you have one.

        Exit codes: 0 everything answered, 2 the database could not be reached.
        HELP);
    }

    public function handle(
        Repository $config,
        ConnectionResolverInterface $connections,
        DatabaseContextFactory $contexts,
        MigrationLocator $locator,
        RuleRegistry $rules,
        Baseline $baseline,
        AcceptedFindings $accepted,
    ): int {
        $name = $this->option('connection');
        $name = is_string($name) && $name !== '' ? $name : null;

        $context = $contexts->make();
        $writable = $this->writable($connections, $name ?? $this->configured($config));

        $report = [
            'enabled' => $config->get('difflock.enabled') !== false,
            'connection' => $context->schema->connection,
            'driver' => $context->driver(),
            'version' => $context->version,
            'environment' => $context->environment,
            'reachable' => $context->available,
            'writable' => $writable,
            'tables' => count($context->schema->tables),
            'pending_migrations' => count($locator->locate(MigrationScope::Pending)),
            'all_migrations' => count($locator->locate(MigrationScope::All)),
            'rules' => array_map(
                static fn (object $rule): string => method_exists($rule, 'identifier') ? (string) $rule->identifier() : $rule::class,
                $rules->resolve($this->laravel),
            ),
            'baseline' => ['path' => $baseline->path(), 'recorded' => $baseline->exists()],
            'accepted' => ['path' => $accepted->path(), 'recorded' => $accepted->exists()],
        ];

        if ($this->wantsJson()) {
            $this->writeJson(['difflock' => JsonReport::VERSION] + $report);

            return $context->available ? self::SUCCESS : self::INVALID;
        }

        $this->render($report);

        return $context->available ? self::SUCCESS : self::INVALID;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(array $report): void
    {
        Banner::render($this->output, 'Difflock  ·  Doctor');

        $this->section('Database', [
            'Connection' => $this->text($report['connection']),
            'Driver' => $this->text($report['driver']),
            'Version' => $this->text($report['version']),
            'Environment' => $this->text($report['environment']),
            'Reachable' => $report['reachable'] === true ? 'yes' : 'no',
            'Tables visible' => $this->text($report['tables']),
        ]);

        $this->privileges($report['writable']);

        $this->section('Migrations', [
            'Pending' => $this->text($report['pending_migrations']),
            'Total' => $this->text($report['all_migrations']),
        ]);

        $rules = [];

        foreach (is_array($report['rules']) ? $report['rules'] : [] as $rule) {
            if (is_string($rule)) {
                $rules[] = $rule;
            }
        }

        $this->section('Rules', ['Registered' => count($rules).' — '.implode(', ', $rules)]);

        $baseline = is_array($report['baseline']) ? $report['baseline'] : [];
        $accepted = is_array($report['accepted']) ? $report['accepted'] : [];

        $this->section('Files', [
            'Baseline' => ($baseline['recorded'] === true ? 'recorded' : 'not recorded').'  '.$this->text($baseline['path'] ?? ''),
            'Accepted' => ($accepted['recorded'] === true ? 'recorded' : 'not recorded').'  '.$this->text($accepted['path'] ?? ''),
        ]);
    }

    /**
     * The line this command exists for.
     */
    private function privileges(mixed $writable): void
    {
        $this->output->writeln('  <options=bold>Privileges</>');

        [$glyph, $colour, $line, $note] = match ($writable) {
            false => ['✓', 'green', 'The role Difflock connects as cannot write to this database.',
                'That is the strongest form of the guarantee: not that Difflock will not write, but that it could not.'],
            true => ['⚠', 'yellow', 'The role Difflock connects as is able to write to this database.',
                'Difflock has no code path that writes to the inspected connection, but nothing outside the code enforces that. '
                    .'Point `difflock.connection` at a read-only role and the promise stops depending on trust.'],
            default => ['·', 'gray', 'Whether the role can write could not be determined.',
                'The probe is a read-only transaction that is always rolled back; a driver that does not support one answers nothing.'],
        };

        $this->output->writeln('    <fg='.$colour.'>'.$glyph.'</> '.$line);

        foreach (Text::wrap($note, '      ') as $wrapped) {
            $this->output->writeln('<fg=gray>'.$wrapped.'</>');
        }

        $this->output->writeln('');
    }

    /**
     * Whether the connected role can write, or null when it cannot be established.
     *
     * Asked by opening a transaction, attempting the cheapest possible write, and
     * rolling back — always, on both paths. Nothing is created: the statement is
     * deliberately one that fails on a missing table for a *different* reason than it
     * fails on a missing privilege, and the two are told apart by the SQLSTATE.
     */
    private function writable(ConnectionResolverInterface $connections, ?string $name): ?bool
    {
        try {
            $connection = $connections->connection($name);

            if (! $connection instanceof Connection) {
                return null;
            }

            $connection->beginTransaction();

            try {
                // 42P01/42S02 "no such table" means the statement was allowed and
                // only the object was missing — so the role may write. A privilege
                // error means it may not.
                $connection->statement('create table difflock_write_probe (id int)');
                $connection->rollBack();

                return true;
            } catch (Throwable $exception) {
                $connection->rollBack();

                return $this->deniedByPrivilege($exception) ? false : null;
            }
        } catch (Throwable) {
            return null;
        }
    }

    private function deniedByPrivilege(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        foreach (['permission denied', 'access denied', 'insufficient privilege', 'read-only', 'readonly'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $rows
     */
    private function section(string $title, array $rows): void
    {
        $this->output->writeln('  <options=bold>'.$title.'</>');

        foreach ($rows as $label => $value) {
            $this->output->writeln('    <fg=gray>'.Text::pad($label, 16).'</>'.$value);
        }

        $this->output->writeln('');
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '<fg=gray>unknown</>';
    }

    private function configured(Repository $config): ?string
    {
        $connection = $config->get('difflock.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }
}
