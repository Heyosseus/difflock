<?php

declare(strict_types=1);

namespace Difflock\Console\Renderers;

use Difflock\CheckupResult;
use Difflock\Diff\SchemaDiff;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The two-line summary at the top of a Difflock run — schema, then migrations —
 * followed by the detail underneath.
 *
 * `--ci` renders the summary and the findings that failed the run, and nothing else.
 * A CI log is read by somebody looking for the reason a build went red, and every
 * line that is not that reason is in their way.
 */
final readonly class CheckupRenderer
{
    public function __construct(
        private DiffRenderer $diffs,
        private ReportRenderer $reports,
    ) {}

    public function render(OutputInterface $output, CheckupResult $result, bool $terse = false): void
    {
        $this->schemaLine($output, $result);
        $this->migrationLine($output, $result);

        $output->writeln('');

        if ($result->drifted() && $result->drift instanceof SchemaDiff) {
            $output->writeln('  <options=bold>Schema drift</>');
            $output->writeln('');

            $this->diffs->render($output, $result->drift);
        }

        // Nothing in scope is already stated by the migration line above. Letting the
        // report renderer speak here would have it say "no migrations were found to
        // analyse" and suggest fixing `--path` — which on an application whose 170
        // migrations have simply all been applied is false, and sends the reader
        // debugging something that is not wrong.
        if ($result->report->migrations !== [] && (! $terse || $result->report->fails($result->threshold))) {
            $this->reports->render($output, $result->report, 'difflock:check');
        }

        $output->writeln($result->failed()
            ? '  <fg=red;options=bold>Result: FAIL</>'
            : '  <fg=green;options=bold>Result: PASS</>');

        $output->writeln('');
    }

    /**
     * The overview: the same verdict, without reprinting the whole analysis.
     *
     * `difflock` used to render the full findings list, which on a real application
     * meant the two summary lines a reader actually came for were buried under
     * everything else. Here it shows the summary, any drift, and only the worst level
     * of finding — then says where the rest are.
     */
    public function overview(OutputInterface $output, CheckupResult $result): void
    {
        $this->schemaLine($output, $result);
        $this->migrationLine($output, $result);

        $output->writeln('');

        if ($result->drifted() && $result->drift instanceof SchemaDiff) {
            $output->writeln('  <options=bold>Schema drift</>');
            $output->writeln('');

            $this->diffs->render($output, $result->drift);
        }

        $summary = $result->report->summary();

        if ($summary->total > 0) {
            $worst = $summary->highest;

            $this->reports->render($output, $result->report->only(atLeast: $worst), 'difflock:lint');

            $remaining = $summary->total - $summary->count($worst);

            if ($remaining > 0) {
                $output->writeln(
                    '  <fg=gray>'.$remaining.' finding'.($remaining === 1 ? '' : 's').' below '
                        .$worst->label().' not shown — php artisan difflock:lint</>',
                );
                $output->writeln('');
            }
        }

        $output->writeln($result->failed()
            ? '  <fg=red;options=bold>Result: FAIL</>'
            : '  <fg=green;options=bold>Result: PASS</>');

        $output->writeln('');
    }

    private function schemaLine(OutputInterface $output, CheckupResult $result): void
    {
        $output->writeln('  <options=bold>Schema</>');

        if ($result->baselineError !== null) {
            $output->writeln('    <fg=red>✗</> The recorded baseline could not be read: '.$result->baselineError);

            return;
        }

        if (! $result->baselineRecorded) {
            $output->writeln('    <fg=gray>·</> No baseline recorded, so drift was not checked.');

            foreach (Text::wrap('Record one with `php artisan difflock:diff --save` and commit it.', '      ') as $line) {
                $output->writeln('<fg=gray>'.$line.'</>');
            }

            return;
        }

        if (! $result->drifted()) {
            $output->writeln('    <fg=green>✓</> No drift detected');

            return;
        }

        $count = $result->drift?->count() ?? 0;

        $output->writeln('    <fg=red>✗</> '.$count.' difference'.($count === 1 ? '' : 's').' from the baseline');
    }

    private function migrationLine(OutputInterface $output, CheckupResult $result): void
    {
        $report = $result->report;
        $analyzed = count($report->migrations);
        $summary = $report->summary();

        $output->writeln('  <options=bold>Migrations</>');

        if ($analyzed === 0) {
            $output->writeln('    <fg=green>✓</> No pending migrations');

            return;
        }

        if ($summary->total === 0) {
            $output->writeln('    <fg=green>✓</> '.$analyzed.' pending migration'
                .($analyzed === 1 ? '' : 's').' analysed, nothing to report');

            return;
        }

        $worst = $summary->highest;
        $failing = $report->fails($result->threshold);

        $output->writeln(
            '    <fg='.($failing ? 'red' : 'yellow').'>'.($failing ? '✗' : '⚠').'</> '
                .$summary->total.' finding'.($summary->total === 1 ? '' : 's')
                .', worst '.$worst->label()
                .' <fg=gray>(threshold '.$result->threshold->label().')</>',
        );
    }
}
