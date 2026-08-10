<?php

declare(strict_types=1);

namespace Difflock\Console\Renderers;

use Difflock\Migration\MigrationFinding;
use Difflock\Migration\MigrationReport;
use Difflock\Risk\RiskLevel;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints a migration analysis: findings grouped by rule and risk, worst first, then
 * the tally underneath.
 *
 * ## Why grouped, and why the prose is printed once
 *
 * The first version printed every finding in full, and on a real application that
 * was unusable: 124 cascading-foreign-key findings meant the same five-line
 * explanation and three-line remediation printed 124 times — about a thousand lines
 * of identical prose. An explanation is a property of the *rule*, not of each place
 * the rule fired, and printing it per occurrence buried the one thing the reader
 * needed, which is the list of places.
 *
 * So a group whose findings all share the same explanation prints it once and then
 * lists the occurrences as one line each. A group whose explanations genuinely
 * differ — `drop-column` names the indexes on each column, `add-index` quotes each
 * table's row count — prints the first few in full and says how many it held back.
 * The distinction is made by comparing the text, so it stays right as rules change.
 *
 * Three things are never abbreviated away: the risk level, whether the operation is
 * destructive, and whether the parser understood the whole file.
 */
final class ReportRenderer
{
    /** How many occurrences a group shows before it starts counting instead. */
    private const int PREVIEW = 3;

    public function render(OutputInterface $output, MigrationReport $report): void
    {
        if ($report->migrations === []) {
            $this->nothingFound($output);

            return;
        }

        foreach ($this->grouped($report->findings) as $group) {
            $this->group($output, $group, $output->isVerbose());
        }

        $this->tally($output, $report);
        $this->warnings($output, $report);
    }

    /**
     * Findings bucketed by rule, risk and the prose they carry, most serious first.
     *
     * Risk is part of the key because one rule legitimately reports at several
     * levels — an index on an empty table and the same index on eight million rows
     * are not the same finding and should not share a heading.
     *
     * The explanation is part of the key too, and that is what makes the grouping
     * work. `foreign-key` reports both cascading deletes and dropped constraints at
     * high, with different prose; keying on the rule alone put them in one bucket
     * that was no longer uniform, so a hundred identical cascade explanations went
     * back to being printed one by one. Keyed on the prose, every bucket is uniform
     * by construction and can always print its explanation once.
     *
     * @param  list<MigrationFinding>  $findings
     * @return list<list<MigrationFinding>>
     */
    private function grouped(array $findings): array
    {
        $groups = [];

        foreach ($findings as $finding) {
            // The prose goes into the key verbatim rather than hashed: a run holds a
            // few hundred findings, so the keys cost nothing, and a hash here would
            // be a digest used for grouping that reads like a digest used for
            // security.
            $key = implode("\0", [
                $finding->risk->value,
                $finding->rule,
                $finding->explanation,
                $finding->suggestion ?? '',
            ]);

            $groups[$key][] = $finding;
        }

        // The findings arrive sorted worst-first, so the groups are already in the
        // right order; array_values just drops the keys.
        return array_values($groups);
    }

    /**
     * @param  list<MigrationFinding>  $group
     */
    private function group(OutputInterface $output, array $group, bool $verbose): void
    {
        $first = $group[0];
        $risk = $first->risk;
        $count = count($group);

        $output->writeln(
            '  <fg='.$risk->colour().';options=bold>'.$risk->glyph().' '.Text::pad($risk->label(), 9).'</>'
                .'<options=bold>'.$first->rule.'</>'
                .($count === 1 ? '' : '  <fg=gray>'.$count.' findings</>'),
        );

        $output->writeln('');

        $this->prose($output, $first, '    ');
        $this->occurrences($output, $group, $verbose);

        $output->writeln('');
    }

    private function prose(OutputInterface $output, MigrationFinding $finding, string $indent): void
    {
        foreach (Text::wrap($finding->explanation, $indent) as $line) {
            $output->writeln('<fg=default>'.$line.'</>');
        }

        if ($finding->suggestion !== null) {
            foreach (Text::wrap('→ '.$finding->suggestion, $indent) as $line) {
                $output->writeln('<fg=gray>'.$line.'</>');
            }
        }

        $output->writeln('');
    }

    /**
     * @param  list<MigrationFinding>  $group
     */
    private function occurrences(OutputInterface $output, array $group, bool $verbose): void
    {
        $shown = $verbose ? $group : array_slice($group, 0, self::PREVIEW);

        foreach ($shown as $finding) {
            $output->writeln('    '.$finding->message.'  <fg=gray>'.$this->where($finding).'</>');
        }

        $this->remainder($output, count($group) - count($shown), $group[0]->rule);
    }

    private function remainder(OutputInterface $output, int $hidden, string $rule): void
    {
        if ($hidden <= 0) {
            return;
        }

        $output->writeln(
            '    <fg=gray>… '.$hidden.' more. Run with -v to list them, or --rule='.$rule.'</>',
        );
    }

    /** Where the finding is, and what it does, in one line. */
    private function where(MigrationFinding $finding): string
    {
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

        return $finding->migration
            .($finding->line === null ? '' : ':'.$finding->line)
            .($flags === [] ? '' : '  ·  '.implode(', ', $flags));
    }

    private function nothingFound(OutputInterface $output): void
    {
        $output->writeln('  <fg=gray>No migrations were found to analyse.</>');

        foreach (Text::wrap(
            'Difflock looks in the application\'s migration paths. Point it somewhere else with '
                .'--path, or add a path to `migrations.paths` in config/difflock.php.',
            '  ',
        ) as $line) {
            $output->writeln('<fg=gray>'.$line.'</>');
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
