<?php

declare(strict_types=1);

namespace Difflock\Migration;

use Difflock\Contracts\MigrationAnalyzer;
use Difflock\Contracts\MigrationRule;
use Difflock\Database\DatabaseContextFactory;
use Difflock\Migration\Parser\MigrationParser;
use Difflock\Migration\Parser\ParsedMigration;
use Difflock\Migration\Parser\SchemaStatement;
use Illuminate\Filesystem\Filesystem;

/**
 * Reads the migrations in scope, hands each schema statement to every rule, and
 * gathers what comes back.
 *
 * The order of the loops is the whole design: one database context for the run,
 * one parse per file, one context per schema statement, every rule asked about each.
 * Nothing here knows what a rule does and no rule knows this exists, which is what
 * lets an application add its own rule without touching a line of Difflock.
 *
 * Findings come back sorted most serious first, then by migration, so the thing that
 * will stop your deploy is the first thing you read.
 */
final readonly class RuleMigrationAnalyzer implements MigrationAnalyzer
{
    /**
     * @param  list<MigrationRule>  $rules
     */
    public function __construct(
        private MigrationLocator $locator,
        private MigrationParser $parser,
        private Filesystem $files,
        private DatabaseContextFactory $contexts,
        private array $rules,
        private IgnoreList $ignore,
        private ?AcceptedFindings $accepted = null,
    ) {}

    public function analyze(MigrationScope $scope = MigrationScope::Pending, array $paths = []): MigrationReport
    {
        $database = $this->contexts->make();

        $migrations = [];
        $findings = [];
        $accepted = [];

        foreach ($this->locator->locate($scope, $paths) as $file) {
            $parsed = $this->parse($file);
            $migrations[] = $parsed;

            foreach ($parsed->statements as $statement) {
                foreach ($this->run($parsed, $statement, $database) as $finding) {
                    // Accepted findings are set aside, not discarded. The report still
                    // counts them, and `--accept` needs them to rewrite the file
                    // without losing what somebody already decided to live with.
                    if ($this->accepted?->accepts($finding) === true) {
                        $accepted[] = $finding;

                        continue;
                    }

                    $findings[] = $finding;
                }
            }
        }

        return new MigrationReport(
            $migrations,
            $this->sort($findings),
            $database->available,
            $this->sort($accepted),
        );
    }

    private function parse(MigrationFile $file): ParsedMigration
    {
        if (! $this->files->isReadable($file->path)) {
            return new ParsedMigration(
                $file->name,
                $file->path,
                warnings: ['The migration file could not be read, so it was not analysed.'],
            );
        }

        return $this->parser->parse($this->files->get($file->path), $file->name, $file->path);
    }

    /**
     * @return list<MigrationFinding>
     */
    private function run(ParsedMigration $migration, SchemaStatement $statement, DatabaseContext $database): array
    {
        $context = new MigrationContext($migration, $statement, $database);

        $findings = [];

        foreach ($this->rules as $rule) {
            foreach ($rule->analyze($context) as $finding) {
                if ($this->ignore->allows($finding)) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * Most serious first; within a level, in the order the migrations run.
     *
     * Every tie is broken explicitly, down to the rule name, so the same input always
     * produces the same output — which is what makes the JSON report diffable and the
     * console output worth asserting on in a test.
     *
     * @param  list<MigrationFinding>  $findings
     * @return list<MigrationFinding>
     */
    private function sort(array $findings): array
    {
        usort($findings, static fn (MigrationFinding $a, MigrationFinding $b): int => $b->risk->rank() <=> $a->risk->rank()
            ?: strcmp($a->migration, $b->migration)
            ?: ($a->line ?? 0) <=> ($b->line ?? 0)
            ?: strcmp($a->rule, $b->rule));

        return $findings;
    }
}
