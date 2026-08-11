<?php

declare(strict_types=1);

namespace Difflock\Console\Commands;

use Difflock\Console\Commands\Concerns\InteractsWithDifflock;
use Difflock\Console\Formatters\JsonReport;
use Difflock\Console\Renderers\Banner;
use Difflock\Console\Renderers\DiffRenderer;
use Difflock\Console\Renderers\Text;
use Difflock\Contracts\SchemaDiffer;
use Difflock\Contracts\SchemaInspector;
use Difflock\Diff\SchemaDiff;
use Difflock\Exceptions\InvalidSnapshot;
use Difflock\Exceptions\MissingBaseline;
use Difflock\Schema\Baseline;
use Difflock\Schema\DatabaseSchema;
use Difflock\Support\SecretHeuristics;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * Compares two schemas: the live one against a recorded baseline, or one connection
 * against another.
 */
final class DiffCommand extends Command
{
    use InteractsWithDifflock;

    protected $signature = 'difflock:diff
        {--save : Record the current schema as the baseline instead of comparing against it}
        {--from= : Compare against this connection instead of the recorded baseline}
        {--to= : Compare this connection instead of the configured one}
        {--format=text : text for a person, json for anything else}';

    protected $description = 'Compare the current database schema against a baseline or another connection';

    protected function configure(): void
    {
        parent::configure();

        $this->setHelp(<<<'HELP'
        Compares two schemas that were both actually observed, and prints the
        difference: <info>+</info> gained, <info>-</info> lost, <info>~</info> altered.

        With no options, it compares the current database against the baseline
        recorded in the file configured as <info>difflock.baseline</info>. Record one first:

            php artisan difflock:diff --save

        Commit that file. From then on, a difference means the database no longer
        matches the schema the team agreed on — which is a claim that can be checked,
        unlike "the database does not match the migrations".

        Pass <info>--from</info> and <info>--to</info> to compare two connections instead, which is how
        you check staging against production.

        <comment>Difflock does not reconstruct an expected schema from migration source.</comment>
        Migrations are executable PHP; that reconstruction cannot be made reliable,
        and a diff built on a guess is worse than no diff. Use difflock:lint to
        analyse what migrations will do, and this command to compare what exists.

        Exit codes: 0 no differences, 1 differences found, 2 a configuration or
        runtime error.
        HELP);
    }

    public function handle(
        SchemaInspector $inspector,
        SchemaDiffer $differ,
        Baseline $baseline,
        Repository $config,
        DiffRenderer $renderer,
    ): int {
        if (! $this->enabled($config)) {
            return self::INVALID;
        }

        $to = $this->connection('to');

        try {
            if ($this->option('save') === true) {
                return $this->save($inspector, $baseline, $to);
            }

            $diff = $this->compare($inspector, $differ, $baseline, $to);
        } catch (MissingBaseline|InvalidSnapshot $exception) {
            $this->components->error($exception->getMessage());

            return self::INVALID;
        } catch (Throwable $exception) {
            $this->components->error('Difflock could not read the schema: '.$exception->getMessage());

            return self::INVALID;
        }

        if ($this->wantsJson()) {
            $this->writeJson(JsonReport::diff($diff));

            return $diff->isEmpty() ? self::SUCCESS : self::FAILURE;
        }

        Banner::render($this->output, 'Difflock  ·  Schema Diff');

        $renderer->render($this->output, $diff);

        return $diff->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    private function compare(
        SchemaInspector $inspector,
        SchemaDiffer $differ,
        Baseline $baseline,
        ?string $to,
    ): SchemaDiff {
        $from = $this->connection('from');

        return $differ->diff(
            $from === null ? $baseline->read() : $inspector->inspect($from),
            $inspector->inspect($to),
        );
    }

    private function save(SchemaInspector $inspector, Baseline $baseline, ?string $connection): int
    {
        $schema = $inspector->inspect($connection);

        $baseline->write($schema);

        $tables = count($schema->tables);

        if ($this->wantsJson()) {
            $this->writeJson([
                'difflock' => JsonReport::VERSION,
                'status' => 'passed',
                'baseline' => $baseline->path(),
                'tables' => $tables,
            ]);

            return self::SUCCESS;
        }

        Banner::render($this->output, 'Difflock  ·  Schema Diff');

        $this->output->writeln('  <fg=green>✓</> Baseline recorded: '.$tables.' table'
            .($tables === 1 ? '' : 's').'.');

        $this->warnAboutSecrets($schema);

        foreach (Text::wrap('Written to '.$baseline->path().'. Commit it, and future runs of '
            .'difflock:diff will report anything that no longer matches.', '    ') as $line) {
            $this->output->writeln('<fg=gray>'.$line.'</>');
        }

        $this->output->writeln('');

        return self::SUCCESS;
    }

    /**
     * Say so before a column default that looks like a credential goes into git.
     *
     * Printed after the file is written rather than before, and it does not refuse:
     * these are shapes, not certainties, and a tool that blocked on a heuristic
     * would be wrong often enough to be turned off. Deleting the file and setting
     * `snapshot.defaults` is a ten-second fix — noticing a year later is not.
     */
    private function warnAboutSecrets(DatabaseSchema $schema): void
    {
        $suspects = SecretHeuristics::suspects($schema);

        if ($suspects === []) {
            return;
        }

        $this->output->writeln('');
        $this->output->writeln('  <fg=yellow>⚠</> '.count($suspects).' column default'
            .(count($suspects) === 1 ? '' : 's').' in this file look like they may hold a credential:');

        foreach (SecretHeuristics::describe($suspects) as $described) {
            $this->output->writeln('    <fg=yellow>·</> '.$described);
        }

        foreach (Text::wrap(
            'Difflock recognises shapes, not secrets, so check before you commit. To keep defaults out '
                .'of the baseline entirely, set `snapshot.defaults` to false in config/difflock.php and '
                .'record it again.',
            '    ',
        ) as $line) {
            $this->output->writeln('<fg=gray>'.$line.'</>');
        }
    }

    private function connection(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
