<?php

declare(strict_types=1);

namespace Difflock\Migration;

/**
 * A migration file on disk, before anything has been read out of it.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class MigrationFile
{
    /**
     * @param  string  $name  The migration name Laravel records: `2026_08_10_120000_add_phone`.
     * @param  bool  $pending  Whether the repository has no record of it having run.
     */
    public function __construct(
        public string $name,
        public string $path,
        public bool $pending = true,
    ) {}
}
