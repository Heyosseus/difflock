<?php

declare(strict_types=1);

namespace Difflock\Console\Commands;

use Difflock\Checkup;
use Difflock\Console\Commands\Concerns\InteractsWithDifflock;
use Difflock\Console\Formatters\JsonReport;
use Difflock\Console\Renderers\Banner;
use Difflock\Console\Renderers\CheckupRenderer;
use Difflock\Console\Renderers\Text;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * The one command to run when you do not know which command to run.
 *
 * Identical in what it checks and what it returns to `difflock:check` — the two share
 * one implementation so they can never disagree — and different only in that it ends
 * by telling you where to go next. A tool whose entry point is a list of other
 * commands has put its own structure in front of the question you arrived with.
 */
final class DifflockCommand extends Command
{
    use InteractsWithDifflock;

    protected $signature = 'difflock
        {--fail-on= : The lowest risk level that should fail the command: safe, low, medium, high or critical}
        {--connection= : The connection to inspect, overriding the configured one}
        {--format=text : text for a person, json for anything else}';

    protected $description = 'What changed, why it matters, and whether it is safe to deploy';

    protected function configure(): void
    {
        parent::configure();

        $this->setHelp(<<<'HELP'
        Answers four questions in one screen: what changed in the schema, what the
        pending migrations will do, how risky that is, and whether it should ship.

        It runs exactly what <info>difflock:check</info> runs and exits with the same codes —
        0 clean, 1 something at or above the threshold, 2 a configuration or runtime
        error. Use <info>difflock:check --ci</info> in a pipeline, where the shorter output is
        worth more than the framing.

        The other commands, when you want one of the halves on its own:

            <info>difflock:diff</info>     compare schemas, or record a baseline
            <info>difflock:lint</info>     analyse migrations and report their risk
            <info>difflock:migrate</info>  analyse, then migrate only if it is safe to
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

        Banner::render($this->output);

        $renderer->overview($this->output, $result);

        foreach (Text::wrap(
            'difflock:diff compares schemas · difflock:lint analyses migrations · '
                .'difflock:migrate runs them only if it is safe to',
            '  ',
        ) as $line) {
            $this->output->writeln('<fg=gray>'.$line.'</>');
        }

        $this->output->writeln('');

        return $result->failed() ? self::FAILURE : self::SUCCESS;
    }
}
