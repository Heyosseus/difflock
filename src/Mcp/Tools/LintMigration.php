<?php

declare(strict_types=1);

namespace Difflock\Mcp\Tools;

use Difflock\Contracts\MigrationAnalyzer;
use Difflock\Mcp\Tool;
use Difflock\Migration\MigrationFinding;
use Difflock\Migration\MigrationReport;
use Difflock\Migration\MigrationScope;
use Throwable;

/**
 * "Is this migration safe?" — asked of a file, or of a draft that does not exist yet.
 *
 * The `source` argument is the important one and it changes the shape of the work.
 * Given only `path`, an agent has to write the file before it can find out the
 * migration is wrong, then edit it, then check again — and every intermediate
 * mistake is on disk in the user's repository. Given `source`, it validates the
 * draft it is holding, fixes it, and writes once.
 *
 * The analysis is identical either way: the same rules against the same live
 * database, so the row counts and existing indexes are real even though the
 * migration is not yet.
 */
final readonly class LintMigration implements Tool
{
    /**
     * How many findings come back before the response starts counting instead.
     *
     * A directory of two hundred migrations can produce hundreds of findings, and an
     * agent that receives all of them has spent its context on a wall of text it
     * cannot act on. The count is always exact; only the list is bounded.
     */
    private const int LIMIT = 25;

    public function __construct(private MigrationAnalyzer $analyzer) {}

    public function name(): string
    {
        return 'difflock_lint_migration';
    }

    public function description(): string
    {
        return 'Analyse a Laravel migration for destructive or risky schema operations, using the '
            .'live database for table sizes and existing indexes. Pass "source" with the migration '
            .'code to check a draft BEFORE writing it to disk — do this while composing a migration, '
            .'then fix and re-check until nothing is above low. Pass "path" instead to check a file '
            .'or directory that already exists. Returns findings with a risk level (safe, low, '
            .'medium, high, critical), whether each is destructive and reversible, and a remediation. '
            .'An empty findings list does not mean safe: read "warnings", which lists what the '
            .'analysis could not read, such as table names built from config or raw DB::statement().';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source' => [
                    'type' => 'string',
                    'description' => 'The full PHP source of a migration, including the opening tag. '
                        .'Use this to check a draft before it is written to disk.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Path to an existing migration file or a directory of them, '
                        .'absolute or relative to the application root.',
                ],
            ],
            'oneOf' => [
                ['required' => ['source']],
                ['required' => ['path']],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        $source = $arguments['source'] ?? null;

        if (is_string($source) && trim($source) !== '') {
            return $this->draft($source);
        }

        $path = $arguments['path'] ?? null;

        if (! is_string($path) || $path === '') {
            return [
                'error' => 'Pass either "source" with the migration code, or "path" to a file.',
                'next' => 'To check a migration you are drafting, pass its full PHP source as "source".',
            ];
        }

        $report = $this->analyzer->analyze(MigrationScope::All, [$path]);

        if ($report->migrations === []) {
            return [
                'error' => 'No migration was found at '.$path.'.',
                'next' => 'Give a path to a .php migration file or a directory of them, or pass '
                    .'"source" instead to check code that is not on disk yet.',
            ];
        }

        return $this->result($report);
    }

    /**
     * Analyse source that is not on disk.
     *
     * Written to a temporary file rather than parsed in memory, because that is the
     * one way the draft goes through *exactly* the path a real migration does — the
     * same locator, parser, rules and database context — instead of a parallel one
     * that could drift out of agreement with it. The file is named the way Laravel
     * names migrations so the locator recognises it, lives in the system temporary
     * directory rather than the user's repository, and is removed on every path out.
     *
     * @return array<string, mixed>
     */
    private function draft(string $source): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'difflock-draft-'.bin2hex(random_bytes(8));
        $file = $directory.DIRECTORY_SEPARATOR.'2000_01_01_000000_draft.php';

        if (! @mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            return ['error' => 'Difflock could not create a temporary directory to analyse the draft.'];
        }

        try {
            file_put_contents($file, $source);

            $report = $this->analyzer->analyze(MigrationScope::All, [$file]);
            $parsed = $report->migrations[0] ?? null;

            // A draft with no schema statements is not a clean bill of health — it is
            // source Difflock could not read as a migration at all. Reporting "no
            // findings" there is the one answer that would make an agent confidently
            // tell the user something unchecked is fine.
            if ($parsed === null || $parsed->statements === []) {
                return [
                    'error' => 'No schema operations were found in that source.',
                    'next' => 'Send the whole file, including the opening <?php tag and the '
                        .'`return new class extends Migration` declaration, with the schema changes '
                        .'inside up(). If the migration only runs raw SQL, Difflock cannot read it.',
                    'warnings' => $parsed->warnings ?? [],
                ];
            }

            return ['analysed_from' => 'source'] + $this->result($report);
        } catch (Throwable $exception) {
            return ['error' => 'Difflock could not analyse the draft: '.$exception->getMessage()];
        } finally {
            // Both removed on every path, including the exception one. A draft is the
            // user's unreleased code and has no business outliving the question.
            @unlink($file);
            @rmdir($directory);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function result(MigrationReport $report): array
    {
        $findings = $report->findings;
        $shown = array_slice($findings, 0, self::LIMIT);

        return [
            'analysed' => count($report->migrations),
            'risk' => $report->highestRisk()->value,
            'counts' => $report->summary()->counts,
            'total_findings' => count($findings),
            'showing' => count($shown),
            'truncated' => count($shown) < count($findings),
            'findings' => array_map(
                static fn (MigrationFinding $finding): array => $finding->toArray(),
                $shown,
            ),
            // Never omitted, and never empty-by-default. A clean findings list over a
            // file the parser could only half read is the one case where an agent
            // would confidently tell the user the migration is fine.
            'warnings' => $report->warnings(),
            'database_available' => $report->databaseAvailable,
        ];
    }
}
