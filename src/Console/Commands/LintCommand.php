<?php

declare(strict_types=1);

namespace Difflock\Console\Commands;

use Difflock\Console\Commands\Concerns\InteractsWithDifflock;
use Difflock\Console\Formatters\JsonReport;
use Difflock\Console\Renderers\Banner;
use Difflock\Console\Renderers\ReportRenderer;
use Difflock\Contracts\MigrationAnalyzer;
use Difflock\Migration\MigrationScope;
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
        that has already run cannot be made safer by a finding.

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

    public function handle(MigrationAnalyzer $analyzer, Repository $config, ReportRenderer $renderer): int
    {
        if (! $this->enabled($config)) {
            return self::INVALID;
        }

        $threshold = $this->threshold($config);

        if (! $threshold instanceof \Difflock\Risk\RiskLevel) {
            return $this->unknownThreshold();
        }

        $scope = $this->option('all') === true ? MigrationScope::All : MigrationScope::Pending;

        $report = $analyzer->analyze($scope, $this->paths());
        $failed = $report->fails($threshold);

        if ($this->wantsJson()) {
            $this->writeJson(JsonReport::lint($report, $threshold, $failed));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        Banner::render($this->output, 'Difflock  ·  Migration Analysis');

        $renderer->render($this->output, $report);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
