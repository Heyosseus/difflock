<?php

declare(strict_types=1);

namespace Difflock\Console\Commands;

use Difflock\Console\Commands\Concerns\InteractsWithDifflock;
use Difflock\Console\Formatters\JsonReport;
use Difflock\Contracts\MigrationAnalyzer;
use Difflock\Database\DatabaseContextFactory;
use Difflock\Migration\DatabaseContext;
use Difflock\Migration\MigrationFinding;
use Difflock\Migration\MigrationReport;
use Difflock\Migration\MigrationScope;
use Difflock\Schema\Table;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

/**
 * Everything known about one migration, written for whoever has to decide about it —
 * increasingly an AI agent.
 *
 * ## What this deliberately is not
 *
 * It does not ask a language model whether your migration is safe. That would be
 * exactly the unfalsifiable guessing this package exists to argue against: a
 * confident paragraph with nothing behind it, indistinguishable from a correct one.
 *
 * The division is the point. **Difflock supplies facts** — this drops a column, the
 * table holds 82,325 rows, two indexes are built on it, the migration has no
 * `down()`. **The agent supplies judgement**, using the facts, with your codebase in
 * front of it. Nothing here is generated, so nothing here can be wrong in the
 * particular way generated text is wrong.
 *
 * That also means no API key, no network call, and no model provider in a package
 * whose whole argument is that it only says what it can check.
 *
 * ## Using it
 *
 *     php artisan difflock:explain 2026_08_11_120000_drop_legacy_token
 *     php artisan difflock:explain database/migrations/2026_08_11_120000_x.php
 *
 * The Markdown output is designed to be pasted into a chat, or read directly by an
 * agent through `difflock_lint_migration` — the same facts, one for a pipe and one
 * for a person.
 */
final class ExplainCommand extends Command
{
    use InteractsWithDifflock;

    protected $signature = 'difflock:explain
        {migration : A migration name, or a path to one}
        {--format=text : text for Markdown, json for the same facts as a document}';

    protected $description = 'Brief a reviewer or an AI agent on exactly what one migration does';

    protected function configure(): void
    {
        parent::configure();

        $this->setHelp(<<<'HELP'
        Gathers everything Difflock knows about one migration — what it changes, the
        state of every table it touches, and every finding against it — and writes it
        as Markdown you can paste into a chat or hand to an agent.

        <comment>Nothing here is generated.</comment> This command asks no language model whether
        your migration is safe; it supplies the facts so that whoever does decide —
        you, a reviewer, or an agent with your codebase in front of it — is deciding
        from evidence rather than from a guess.

        Agents can reach the same facts directly over MCP: see difflock:mcp.

        Exit codes: 0 the briefing was written, 2 no such migration.
        HELP);
    }

    public function handle(
        MigrationAnalyzer $analyzer,
        DatabaseContextFactory $contexts,
        Repository $config,
    ): int {
        if (! $this->enabled($config)) {
            return self::INVALID;
        }

        $wanted = $this->argument('migration');
        $wanted = is_string($wanted) ? $wanted : '';

        $report = $analyzer->analyze(MigrationScope::All, $this->searchPaths($wanted));
        $named = $this->matching($report, $wanted);

        if ($named === null) {
            $this->components->error('No migration matching "'.$wanted.'" was found.');

            return self::INVALID;
        }

        [$name, $findings] = $named;
        $database = $contexts->make();
        $tables = $this->tables($report, $name);

        if ($this->wantsJson()) {
            $this->writeJson([
                'difflock' => JsonReport::VERSION,
                'migration' => $name,
                'tables' => $this->tableFacts($tables, $database),
                'findings' => array_map(
                    static fn (MigrationFinding $finding): array => $finding->toArray(),
                    $findings,
                ),
                'warnings' => $report->warnings(),
            ]);

            return self::SUCCESS;
        }

        $this->markdown($name, $findings, $tables, $database, $report);

        return self::SUCCESS;
    }

    /**
     * @param  list<MigrationFinding>  $findings
     * @param  list<string>  $tables
     */
    private function markdown(
        string $name,
        array $findings,
        array $tables,
        DatabaseContext $database,
        MigrationReport $report,
    ): void {
        $out = $this->output;

        $out->writeln('# Migration briefing: '.$name);
        $out->writeln('');
        $out->writeln('Facts gathered by Difflock. Nothing below is generated — every line is either');
        $out->writeln('read from the database or determined from the migration source.');
        $out->writeln('');

        $out->writeln('## Tables it touches');
        $out->writeln('');

        if ($tables === []) {
            $out->writeln('- None that could be determined from the source.');
        }

        foreach ($tables as $table) {
            $live = $database->table($table);
            $rows = $database->rows($table);

            if (! $live instanceof Table) {
                $out->writeln('- **'.$table.'** — does not exist on the inspected database.');

                continue;
            }

            $out->writeln('- **'.$table.'** — '
                .($rows === null ? 'row count unknown' : number_format($rows).' rows')
                .', '.count($live->columns).' columns, '.count($live->indexes).' indexes, '
                .count($live->foreignKeys).' foreign keys.');
        }

        $out->writeln('');
        $out->writeln('## Findings');
        $out->writeln('');

        if ($findings === []) {
            $out->writeln('None. Check the warnings below before concluding it is safe.');
        }

        foreach ($findings as $finding) {
            $out->writeln('### '.$finding->risk->label().' — '.$finding->rule);
            $out->writeln('');
            $out->writeln('- **What:** '.$finding->message);

            if ($finding->context !== null) {
                $out->writeln('- **Context:** '.$finding->context);
            }

            $out->writeln('- **Destructive:** '.($finding->destructive ? 'yes' : 'no')
                .' · **Reversible:** '.($finding->reversible ? 'yes' : 'no')
                .($finding->conditional ? ' · **Conditional:** may not run' : ''));
            $out->writeln('- **Why it matters:** '.$finding->explanation);

            if ($finding->suggestion !== null) {
                $out->writeln('- **Suggested remedy:** '.$finding->suggestion);
            }

            $out->writeln('');
        }

        $warnings = $report->warnings();

        if ($warnings !== [] || ! $database->available) {
            $out->writeln('## What this analysis could not see');
            $out->writeln('');

            if (! $database->available) {
                $out->writeln('- The database could not be reached, so no row count or live-schema');
                $out->writeln('  fact above was available.');
            }

            foreach ($warnings as $warning) {
                $out->writeln('- '.$warning);
            }

            $out->writeln('');
        }

        $out->writeln('## Deciding');
        $out->writeln('');
        $out->writeln('Difflock reports risk, not permission. `reversible` means a `down()` exists,');
        $out->writeln('never that the data comes back. Weigh the findings against what this change is');
        $out->writeln('for, and whether the tables above are large enough for the cost to be felt.');
    }

    /**
     * @return list<string>
     */
    private function searchPaths(string $wanted): array
    {
        // A path narrows the search to that file; a bare name searches the configured
        // migration paths.
        return str_ends_with($wanted, '.php') ? [$this->absolute($wanted)] : [];
    }

    /**
     * @return array{0: string, 1: list<MigrationFinding>}|null
     */
    private function matching(MigrationReport $report, string $wanted): ?array
    {
        $needle = str_replace('.php', '', basename($wanted));

        foreach ($report->migrations as $migration) {
            if ($migration->name === $needle || str_contains($migration->name, $needle)) {
                return [$migration->name, $report->findingsFor($migration->name)];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function tables(MigrationReport $report, string $name): array
    {
        foreach ($report->migrations as $migration) {
            if ($migration->name === $name) {
                return $migration->tables();
            }
        }

        return [];
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, mixed>
     */
    private function tableFacts(array $tables, DatabaseContext $database): array
    {
        $facts = [];

        foreach ($tables as $table) {
            $live = $database->table($table);

            $facts[$table] = [
                'exists' => $live instanceof Table,
                'rows' => $database->rows($table),
                'columns' => $live instanceof Table ? count($live->columns) : null,
                'indexes' => $live instanceof Table ? count($live->indexes) : null,
            ];
        }

        return $facts;
    }
}
