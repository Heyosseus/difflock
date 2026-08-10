<?php

declare(strict_types=1);

namespace Difflock\Console\Commands;

use Difflock\Checkup;
use Difflock\Console\Commands\Concerns\InteractsWithDifflock;
use Difflock\Console\Formatters\JsonReport;
use Difflock\Console\Renderers\Banner;
use Difflock\Console\Renderers\CheckupRenderer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * The build gate: schema drift and migration risk in one command, with an exit code
 * a pipeline can act on.
 */
final class CheckCommand extends Command
{
    use InteractsWithDifflock;

    protected $signature = 'difflock:check
        {--ci : Print only the summary and whatever failed the run}
        {--fail-on= : The lowest risk level that should fail the command: safe, low, medium, high or critical}
        {--connection= : The connection to inspect, overriding the configured one}
        {--format=text : text for a person, json for anything else}';

    protected $description = 'Check the schema for drift and the pending migrations for risk';

    protected function configure(): void
    {
        parent::configure();

        $this->setHelp(<<<'HELP'
        Runs both halves of Difflock and fails the build if either one should.

        <info>Schema</info> — compares the live schema against the baseline recorded in the
        file configured as <info>difflock.baseline</info>. With no baseline recorded, drift is
        not checked and the command says so rather than reporting a clean result.

        <info>Migrations</info> — analyses every pending migration and fails on any finding at
        or above the threshold, which is <info>critical</info> unless configured otherwise or
        overridden with <info>--fail-on</info>.

        Exit codes, which is the part CI cares about:

            0   nothing found at or above the threshold
            1   findings above the threshold, or the schema has drifted
            2   a configuration or runtime error — including a baseline that
                exists and could not be read

        <comment>2 is not a pass.</comment> Treat it as a failure in your pipeline; it means the
        check did not run, which is different from running and finding nothing.

        Add <info>--ci</info> for output pared down to the summary and the reasons for failure,
        or <info>--format=json</info> for a document with no ANSI in it at all.
        HELP);
    }

    public function handle(Checkup $checkup, Repository $config, CheckupRenderer $renderer): int
    {
        if (! $this->enabled($config)) {
            return self::INVALID;
        }

        $threshold = $this->threshold($config);

        if (! $threshold instanceof \Difflock\Risk\RiskLevel) {
            return $this->unknownThreshold();
        }

        $connection = $this->option('connection');

        try {
            $result = $checkup->run($threshold, is_string($connection) && $connection !== '' ? $connection : null);
        } catch (Throwable $exception) {
            $this->components->error('Difflock could not complete the check: '.$exception->getMessage());

            return self::INVALID;
        }

        if ($this->wantsJson()) {
            $this->writeJson(JsonReport::check($result->drift, $result->report, $threshold, $result->failed()));

            return $result->failed() ? self::FAILURE : self::SUCCESS;
        }

        Banner::render($this->output, $this->option('ci') === true ? 'Difflock CI' : 'Difflock');

        $renderer->render($this->output, $result, $this->option('ci') === true);

        return $result->failed() ? self::FAILURE : self::SUCCESS;
    }
}
