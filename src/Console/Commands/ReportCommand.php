<?php

declare(strict_types=1);

namespace Difflock\Console\Commands;

use Difflock\Checkup;
use Difflock\CheckupResult;
use Difflock\Console\Commands\Concerns\InteractsWithDifflock;
use Difflock\Console\Formatters\HtmlReport;
use Difflock\Console\Formatters\JsonReport;
use Difflock\Console\Renderers\Banner;
use Difflock\Console\Renderers\Text;
use Difflock\Risk\RiskLevel;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * Writes the whole run — drift and findings — to a file somebody can open.
 *
 * The console output is for the person who ran the command. This is for everybody
 * else: attach it to a pull request, keep it as a CI artifact, send it to whoever
 * has to approve the deploy. The HTML is entirely self-contained, because an
 * artifact opened from a `file://` URL has no network to fetch anything with.
 *
 * It never changes the verdict — the exit code is the one `difflock:check` would
 * have given, so a report and a gate can never disagree.
 */
final class ReportCommand extends Command
{
    use InteractsWithDifflock;

    protected $signature = 'difflock:report
        {--output= : Where to write the report, default storage/difflock/report.html}
        {--fail-on= : The lowest risk level that should fail the command}
        {--connection= : The connection to inspect, overriding the configured one}
        {--format=html : html for a file somebody opens, json for a machine}';

    protected $description = 'Write the schema drift and migration analysis to a shareable file';

    protected function configure(): void
    {
        parent::configure();

        $this->setHelp(<<<'HELP'
        Runs exactly what <info>difflock:check</info> runs and writes it to a file instead of
        the terminal, exiting with the same codes.

            php artisan difflock:report
            php artisan difflock:report --output=build/difflock.html

        The HTML has no external stylesheet, font or script, so it renders correctly
        as a CI artifact opened straight from disk. Everything in it — table names,
        column names, rule messages — is escaped, because all of it comes from a
        database or from migration source rather than from this package.

        <info>--format=json</info> writes the same document as difflock:check, for anything
        that would rather parse than read.
        HELP);
    }

    public function handle(Checkup $checkup, Repository $config, Filesystem $files, HtmlReport $html): int
    {
        if (! $this->enabled($config)) {
            return self::INVALID;
        }

        $threshold = $this->threshold($config);

        if (! $threshold instanceof RiskLevel) {
            return $this->unknownThreshold();
        }

        $connection = $this->option('connection');

        try {
            $result = $this->whileWorking(
                'Building the report',
                fn (): CheckupResult => $checkup->run(
                    $threshold,
                    is_string($connection) && $connection !== '' ? $connection : null,
                ),
            );
        } catch (Throwable $exception) {
            $this->components->error('Difflock could not complete the report: '.$exception->getMessage());

            return self::INVALID;
        }

        $json = $this->option('format') === 'json';
        $path = $this->path($json);

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $json
            ? JsonReport::encode(JsonReport::check($result->drift, $result->report, $threshold, $result->failed()))."\n"
            : $html->render($result, $this->generatedAt(), $this->application($config)));

        Banner::render($this->output, 'Difflock  ·  Report');

        $this->output->writeln('  <fg=green>✓</> Written to '.$path);

        foreach (Text::wrap(
            $result->failed()
                ? 'The run failed — the report says why, and this command exits with the same code '
                    .'difflock:check would have.'
                : 'Nothing at or above the threshold.',
            '    ',
        ) as $line) {
            $this->output->writeln('<fg=gray>'.$line.'</>');
        }

        $this->output->writeln('');

        return $result->failed() ? self::FAILURE : self::SUCCESS;
    }

    private function path(bool $json): string
    {
        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            return $this->absolute($output);
        }

        return $this->laravel->storagePath('difflock/report.'.($json ? 'json' : 'html'));
    }

    /**
     * A fixed-format timestamp rather than a localised one, so two reports of the
     * same run diff cleanly.
     */
    private function generatedAt(): string
    {
        return gmdate('Y-m-d H:i').' UTC';
    }

    private function application(Repository $config): ?string
    {
        $name = $config->get('app.name');

        return is_string($name) && $name !== '' ? $name : null;
    }
}
