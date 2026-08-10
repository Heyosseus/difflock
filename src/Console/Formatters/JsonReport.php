<?php

declare(strict_types=1);

namespace Difflock\Console\Formatters;

use Difflock\Diff\SchemaDiff;
use Difflock\Migration\MigrationReport;
use Difflock\Risk\RiskLevel;

/**
 * The machine-readable form of everything Difflock reports.
 *
 * Two properties are load-bearing and are worth stating plainly:
 *
 *   - **No ANSI, ever.** JSON is written straight to the output with no style tags in
 *     it, so piping a command into `jq` works whether or not the terminal is a TTY.
 *   - **A stable shape.** Every document carries `difflock` (the format version),
 *     `status` and `risk` at the top level. Keys are added in minor versions;
 *     removing or repurposing one needs a major. The README documents each shape.
 */
final class JsonReport
{
    /** Bumped only when the shape changes incompatibly. */
    public const int VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function lint(MigrationReport $report, RiskLevel $threshold, bool $failed): array
    {
        return [
            'difflock' => self::VERSION,
            'status' => $failed ? 'failed' : 'passed',
            'risk' => $report->highestRisk()->value,
            'threshold' => $threshold->value,
        ] + $report->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public static function diff(SchemaDiff $diff): array
    {
        return [
            'difflock' => self::VERSION,
            'status' => $diff->isEmpty() ? 'passed' : 'failed',
            'schema' => $diff->toArray(),
        ];
    }

    /**
     * The combined document `difflock:check` and `difflock` emit.
     *
     * `schema` is null — rather than absent, and rather than an empty diff — when no
     * baseline was recorded and drift was therefore not checked. A consumer must be
     * able to tell "no drift" from "nobody looked".
     *
     * @return array<string, mixed>
     */
    public static function check(
        ?SchemaDiff $diff,
        MigrationReport $report,
        RiskLevel $threshold,
        bool $failed,
    ): array {
        return [
            'difflock' => self::VERSION,
            'status' => $failed ? 'failed' : 'passed',
            'risk' => $report->highestRisk()->value,
            'threshold' => $threshold->value,
            'schema' => $diff?->toArray(),
            'migrations' => $report->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public static function encode(array $document): string
    {
        return json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
