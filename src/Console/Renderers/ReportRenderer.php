<?php

declare(strict_types=1);

namespace Difflock\Console\Renderers;

use Difflock\Migration\MigrationFinding;
use Difflock\Migration\MigrationReport;
use Difflock\Risk\RiskLevel;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints a migration analysis, in one of two modes.
 *
 * ## Summary, by default
 *
 * A count per risk level with the rules contributing to it, the worst few findings,
 * and where to find the rest. Its length does not depend on how many findings there
 * are, which is the entire point: this renderer previously printed 693 lines against
 * a real 170-migration application, and output that long is not read, it is scrolled
 * past — so the findings in it are worth nothing however correct they are.
 *
 * ## Detail, with `-v`
 *
 * Every finding, grouped by rule, risk and the prose it carries, so a shared
 * explanation is printed once for the whole group rather than once per finding.
 *
 * That grouping only works because rules keep per-occurrence facts out of their
 * explanations and put them in {@see MigrationFinding::$context} instead. When they
 * did not — when `drop-column` appended each table's row count to its paragraph —
 * every finding became a group of one and the detail view was ten times longer than
 * it needed to be.
 *
 * Four things are never abbreviated away, in either mode: the risk tally, the count
 * of accepted findings, whether the database was reachable, and what the parser
 * could not read. They are what a reader would not know to ask for, and dropping
 * them is how a summary becomes a lie.
 */
final class ReportRenderer
{
    /** How many occurrences a group shows before it starts counting instead. */
    private const int PREVIEW = 3;

    /**
     * @param  string  $command  What to point the reader at for the full detail.
     */
    public function render(OutputInterface $output, MigrationReport $report, string $command = 'difflock:lint'): void
    {
        if ($report->migrations === []) {
            $this->nothingFound($output);

            return;
        }

        // Summary unless asked otherwise. On a real application this is the
        // difference between twenty lines and seven hundred, and seven hundred lines
        // of correct findings are worth nothing because nobody reads them.
        $output->isVerbose()
            ? $this->detail($output, $report)
            : $this->summary($output, $report, $command);

        $this->warnings($output, $report);
    }

    /** Every finding, grouped. What `-v` gives you. */
    public function detail(OutputInterface $output, MigrationReport $report): void
    {
        foreach ($this->grouped($report->findings) as $group) {
            $this->group($output, $group, true);
        }

        $this->tally($output, $report);
    }

    /**
     * The bounded view: what was found, the worst of it, and where the rest is.
     *
     * Length is independent of the number of findings — one line per risk level that
     * has any, plus a fixed-size worst list. The tally, the accepted count and the
     * parser warnings are never abbreviated away, because they are the things a
     * reader would not know to ask for.
     */
    public function summary(OutputInterface $output, MigrationReport $report, string $command): void
    {
        $summary = $report->summary();

        if ($summary->total === 0) {
            $output->writeln('  <fg=green>✓</> Nothing to report.');
            $output->writeln('');
            $this->analysed($output, $report);

            return;
        }

        foreach (array_reverse(RiskLevel::ascending()) as $level) {
            $count = $summary->count($level);

            if ($count === 0) {
                continue;
            }

            $output->writeln(
                '  <fg='.$level->colour().';options=bold>'.$level->glyph().' '.Text::pad($level->label(), 9).'</>'
                    .str_pad((string) $count, 4, ' ', STR_PAD_LEFT)
                    .'   <fg=gray>'.implode(', ', $this->rulesAt($report->findings, $level)).'</>',
            );
        }

        $output->writeln('');
        $output->writeln('  <options=bold>Worst</>');

        foreach (array_slice($report->findings, 0, self::PREVIEW) as $finding) {
            $output->writeln('    '.$finding->message);
            $output->writeln('      <fg=gray>'.$this->where($finding).'</>');
        }

        $output->writeln('');
        $this->analysed($output, $report);

        foreach ([
            $command.' -v' => 'every finding in full',
            $command.' --rule=NAME' => 'one rule at a time',
            'difflock:report' => 'a shareable HTML report',
        ] as $invocation => $describes) {
            $output->writeln('  <fg=gray>→ '.Text::pad($invocation, 30).$describes.'</>');
        }

        $output->writeln('');
    }

    /**
     * The rules contributing to a level, so the tally says what kind of problem it is
     * rather than only how much of it there is.
     *
     * @param  list<MigrationFinding>  $findings
     * @return list<string>
     */
    private function rulesAt(array $findings, RiskLevel $level): array
    {
        $rules = [];

        foreach ($findings as $finding) {
            if ($finding->risk === $level) {
                $rules[$finding->rule] = true;
            }
        }

        return array_keys($rules);
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
        // `-v` means every finding — the summary is where brevity lives now, so
        // truncating here as well would leave no way to see the whole picture.
        $shown = $verbose ? $group : array_slice($group, 0, self::PREVIEW);

        foreach ($shown as $finding) {
            $output->writeln('    '.$finding->message.'  <fg=gray>'.$this->where($finding).'</>');

            if ($finding->context !== null) {
                $output->writeln('      <fg=gray>'.$finding->context.'</>');
            }
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

        $output->writeln('  <options=bold>Risk</>');

        foreach (RiskLevel::ascending() as $level) {
            $count = $summary->count($level);

            $output->writeln(
                '    <fg='.($count > 0 ? $level->colour() : 'gray').'>'.$level->glyph().'</> '
                    .Text::pad(ucfirst($level->value).':', 11).$count,
            );
        }

        $output->writeln('');
        $this->analysed($output, $report);
    }

    /**
     * How much was looked at, and what was held back.
     *
     * Printed in both modes. An accepted backlog nobody can see is a backlog that
     * quietly becomes permanent.
     */
    private function analysed(OutputInterface $output, MigrationReport $report): void
    {
        $analyzed = count($report->migrations);
        $total = $report->summary()->total;

        $output->writeln(
            '  <fg=gray>'.$analyzed.' migration'.($analyzed === 1 ? '' : 's').' analysed'
                .($total === 0 ? '' : ' · '.$total.' finding'.($total === 1 ? '' : 's')).'.</>',
        );

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
