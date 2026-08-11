<?php

declare(strict_types=1);

namespace Difflock\Mcp\Tools;

use Difflock\Contracts\MigrationAnalyzer;
use Difflock\Mcp\Tool;
use Difflock\Migration\MigrationFinding;
use Difflock\Migration\MigrationScope;

/**
 * "I just wrote this migration — what's wrong with it?"
 *
 * The tool an agent should reach for immediately after writing a migration, and the
 * reason this server exists. It analyses one file against the live database, so the
 * answer accounts for how many rows the table actually holds and what is actually
 * built on the column — the things that separate a harmless drop from an incident,
 * and exactly the things a model writing code cannot see.
 */
final readonly class LintMigration implements Tool
{
    public function __construct(private MigrationAnalyzer $analyzer) {}

    public function name(): string
    {
        return 'difflock_lint_migration';
    }

    public function description(): string
    {
        return 'Analyse a Laravel migration file for destructive or risky schema operations, using '
            .'the live database for table sizes and existing indexes. Call this immediately after '
            .'writing or editing a migration, before showing it to the user. Returns findings with a '
            .'risk level (safe, low, medium, high, critical), whether each operation is destructive '
            .'and reversible, and a concrete remediation. An empty findings list means nothing was '
            .'found; check the warnings field, which lists anything the analysis could not read.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Path to the migration file, absolute or relative to the '
                        .'application root. A directory analyses every migration in it.',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function handle(array $arguments): array
    {
        $path = $arguments['path'] ?? null;

        if (! is_string($path) || $path === '') {
            return ['error' => 'A path is required.'];
        }

        $report = $this->analyzer->analyze(MigrationScope::All, [$path]);

        if ($report->migrations === []) {
            return [
                'error' => 'No migration was found at '.$path.'.',
                'hint' => 'Give a path to a .php migration file or a directory of them.',
            ];
        }

        return [
            'analysed' => count($report->migrations),
            'risk' => $report->highestRisk()->value,
            'counts' => $report->summary()->counts,
            'findings' => array_map(
                static fn (MigrationFinding $finding): array => $finding->toArray(),
                $report->findings,
            ),
            // Never omitted. A clean result over a file the parser could only half read
            // is the one case where an agent would confidently tell the user it is fine.
            'warnings' => $report->warnings(),
            'database_available' => $report->databaseAvailable,
        ];
    }
}
