<?php

declare(strict_types=1);

namespace Difflock\Console\Renderers;

use Difflock\Migration\MigrationFinding;
use Difflock\Migration\MigrationReport;
use Difflock\Risk\RiskLevel;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints a migration analysis: the findings, grouped by migration, worst first, then
 * the tally underneath.
 *
 * Three things are always shown and never abbreviated away — the risk level, whether
 * the operation is destructive, and whether the parser understood the whole file.
 * The last of those is the one a reader would never think to ask for and most needs:
 * a clean report over a file Difflock could only half read is not a clean report.
 */
final class ReportRenderer
{
    public function render(OutputInterface $output, MigrationReport $report): void
    {
        if ($report->migrations === []) {
            $output->writeln('  <fg=gray>No migrations were found to analyse.</>');

            foreach (Text::wrap(
                'Difflock looks in the application\'s migration paths. Point it somewhere else with '
                    .'--path, or add a path to `migrations.paths` in config/difflock.php.',
                '  ',
            ) as $line) {
                $output->writeln('<fg=gray>'.$line.'</>');
            }

            $output->writeln('');

            return;
        }

        foreach ($report->migrations as $migration) {
            $findings = $report->findingsFor($migration->name);

            if ($findings === []) {
                continue;
            }

            $output->writeln('  <options=bold>'.$migration->name.'</>');
            $output->writeln('');

            foreach ($findings as $finding) {
                $this->finding($output, $finding);
            }
        }

        $this->tally($output, $report);
        $this->warnings($output, $report);
    }

    private function finding(OutputInterface $output, MigrationFinding $finding): void
    {
        $risk = $finding->risk;

        $flags = [];

        if ($finding->destructive) {
            $flags[] = 'destructive';
        }

        if (! $finding->reversible) {
            $flags[] = 'not reversible';
        }

        if ($finding->conditional) {
            $flags[] = 'conditional';
        }

        $output->writeln(
            '    <fg='.$risk->colour().';options=bold>'.$risk->glyph().' '.Text::pad($risk->label(), 9).'</>'
                .$finding->message,
        );

        $meta = '<fg=gray>'.$finding->rule
            .($finding->line === null ? '' : ':'.$finding->line)
            .($flags === [] ? '' : '  ·  '.implode(', ', $flags))
            .'</>';

        $output->writeln('      '.$meta);

        foreach (Text::wrap($finding->explanation, '      ') as $line) {
            $output->writeln('<fg=default>'.$line.'</>');
        }

        if ($finding->suggestion !== null) {
            foreach (Text::wrap('→ '.$finding->suggestion, '      ') as $line) {
                $output->writeln('<fg=gray>'.$line.'</>');
            }
        }

        $output->writeln('');
    }

    private function tally(OutputInterface $output, MigrationReport $report): void
    {
        $summary = $report->summary();
        $analyzed = count($report->migrations);

        $output->writeln('  <options=bold>Risk</>');

        foreach (RiskLevel::ascending() as $level) {
            $count = $summary->count($level);

            $output->writeln(
                '    <fg='.($count > 0 ? $level->colour() : 'gray').'>'.$level->glyph().'</> '
                    .Text::pad(ucfirst($level->value).':', 11).$count,
            );
        }

        $output->writeln('');
        $output->writeln('  <fg=gray>'.$analyzed.' migration'.($analyzed === 1 ? '' : 's').' analysed.</>');

        // Never silent: an accepted backlog that nobody can see is a backlog that
        // quietly becomes permanent.
        if ($report->accepted !== []) {
            $output->writeln(
                '  <fg=gray>'.count($report->accepted).' previously accepted finding'
                    .(count($report->accepted) === 1 ? '' : 's').' not shown.</>',
            );
        }

        $output->writeln('');
    }

    private function warnings(OutputInterface $output, MigrationReport $report): void
    {
        if (! $report->databaseAvailable) {
            $output->writeln(
                '  <fg=yellow>⚠</> The database could not be reached, so no finding here took table '
                    .'size or the live schema into account.',
            );
            $output->writeln('');
        }

        $warnings = $report->warnings();

        if ($warnings === []) {
            return;
        }

        $output->writeln('  <options=bold>Not fully analysed</>');

        foreach ($warnings as $warning) {
            foreach (Text::wrap('· '.$warning, '    ') as $line) {
                $output->writeln('<fg=yellow>'.$line.'</>');
            }
        }

        $output->writeln('');
    }
}
