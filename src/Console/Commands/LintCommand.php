<?php

declare(strict_types=1);

namespace Difflock\Console\Commands;

use Difflock\Console\Commands\Concerns\InteractsWithDifflock;
use Difflock\Console\Formatters\JsonReport;
use Difflock\Console\Renderers\Banner;
use Difflock\Console\Renderers\ReportRenderer;
use Difflock\Console\Renderers\Text;
use Difflock\Contracts\MigrationAnalyzer;
use Difflock\Migration\AcceptedFindings;
use Difflock\Migration\MigrationReport;
use Difflock\Migration\MigrationScope;
use Difflock\Risk\RiskLevel;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

/**
 * Analyses migrations and reports what each operation risks.
 */
final class LintCommand extends Command
{
    use InteractsWithDifflock;

    protected $signature = 'difflock:lint
        {--all : Analyse every migration, not only the pending ones}
        {--accept : Record everything found as accepted, so only new findings fail from now on}
        {--rule= : Show only findings from this rule, * wildcards allowed}
        {--table= : Show only findings about this table, * wildcards allowed}
        {--risk= : Show only findings at or above this level}
        {--path=* : Analyse only migrations in these directories}
        {--realpath : Treat the given paths as absolute rather than relative to the application}
        {--fail-on= : The lowest risk level that should fail the command: safe, low, medium, high or critical}
        {--format=text : text for a person, json for anything else}';

    protected $description = 'Analyse migrations and report the risk of each schema operation';

    /**
     * The help text is where the limits of static analysis get stated, because this
     * is the command whose output could most easily be mistaken for a guarantee.
     */
    protected function configure(): void
    {
        parent::configure();

        $this->setHelp(<<<'HELP'
        Reads the source of each migration in scope and reports what its schema
        operations risk — dropped columns, renames, cascading foreign keys, indexes
        added to large tables, NOT NULL columns added to populated ones.

        Only pending migrations are analysed unless you pass <info>--all</info>. A migration
        that has already run cannot be made safer by a finding — so when nothing is
        pending, this command audits every migration instead of printing nothing,
        and says that is what it did.

        <info>--accept</info> records everything currently found into the accepted-findings
        file and commits you to nothing else. From then on the gate fails only on
        findings that are <comment>new</comment>. That is how you put this in CI on an existing
        codebase without a wall of red on day one: the backlog stays visible and
        counted, it just stops blocking work it cannot retroactively prevent.

            php artisan difflock:lint --all --accept   # accept today's backlog
            php artisan difflock:lint                  # fails only on new findings

        Where a database is reachable, findings take the live schema and table sizes
        into account: the same DROP COLUMN reads differently against an empty table
        and against eight million rows. Where it is not, the analysis still runs, and
        says at the top that it ran blind.

        <comment>This is static analysis, and migrations are executable PHP.</comment> A migration
        that branches on config, loops over a runtime list, or calls DB::statement()
        cannot be fully read from source. Difflock reports what it could not read
        rather than reporting nothing, and never claims to have understood a file it
        did not.

        Exit codes: 0 nothing at or above the threshold, 1 something was,
        2 a configuration or runtime error.
        HELP);
    }

    public function __construct(private readonly AcceptedFindings $accepted)
    {
        parent::__construct();
    }

    public function handle(MigrationAnalyzer $analyzer, Repository $config, ReportRenderer $renderer): int
    {
        if (! $this->enabled($config)) {
            return self::INVALID;
        }

        $threshold = $this->threshold($config);

        if (! $threshold instanceof RiskLevel) {
            return $this->unknownThreshold();
        }

        $scope = $this->option('all') === true ? MigrationScope::All : MigrationScope::Pending;
        $paths = $this->paths();

        $report = $analyzer->analyze($scope, $paths);

        // Nothing pending is the ordinary state of a machine that is up to date, and
        // printing nothing there is how a useful tool gets mistaken for a broken one.
        // Audit what already shipped instead, and say so rather than pretending the
        // empty scope was what was asked for.
        $audited = $scope === MigrationScope::Pending && $report->migrations === [];

        if ($audited) {
            $report = $analyzer->analyze(MigrationScope::All, $paths);
        }

        if ($this->option('accept') === true) {
            return $this->accept($report);
        }

        $failed = $report->fails($threshold);

        if ($this->wantsJson()) {
            $this->writeJson(JsonReport::lint($report, $threshold, $failed));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        // Filters narrow what is printed and never what is judged: the exit code
        // above came from the whole report, so `--rule=add-index` cannot be used to
        // make a failing build pass.
        $shown = $report->only($this->filter('rule'), $this->filter('table'), $this->riskFilter());

        Banner::render($this->output, 'Difflock  ·  Migration Analysis');

        if ($audited) {
            foreach (Text::wrap(
                'Nothing is pending, so this is an audit of every migration already applied. '
                    .'On a branch that adds one, the same command reports only that migration.',
                '  ',
            ) as $line) {
                $this->output->writeln('<fg=gray>'.$line.'</>');
            }

            $this->output->writeln('');
        }

        $renderer->render($this->output, $shown);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function filter(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function riskFilter(): ?RiskLevel
    {
        $value = $this->filter('risk');

        return $value === null ? null : RiskLevel::tryFrom(strtolower($value));
    }

    /**
     * Record everything found as accepted.
     *
     * Writes the findings *and* the ones already accepted, so running it twice is
     * idempotent rather than progressively forgetting the backlog.
     */
    private function accept(MigrationReport $report): int
    {
        $count = $this->accepted->write($report->allFindings());

        if ($this->wantsJson()) {
            $this->writeJson([
                'difflock' => JsonReport::VERSION,
                'status' => 'passed',
                'accepted' => $count,
                'file' => $this->accepted->path(),
            ]);

            return self::SUCCESS;
        }

        Banner::render($this->output, 'Difflock  ·  Migration Analysis');

        $this->output->writeln('  <fg=green>✓</> Accepted '.$count.' finding'.($count === 1 ? '' : 's').'.');

        foreach (Text::wrap(
            'Written to '.$this->accepted->path().'. Commit it, and difflock:lint will fail only on '
                .'findings that are new. Delete a line to bring one back.',
            '    ',
        ) as $line) {
            $this->output->writeln('<fg=gray>'.$line.'</>');
        }

        $this->output->writeln('');

        return self::SUCCESS;
    }
}
