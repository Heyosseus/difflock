<?php

declare(strict_types=1);

namespace Difflock\Contracts;

use Difflock\Migration\MigrationReport;
use Difflock\Migration\MigrationScope;

/**
 * Runs every registered rule over the migrations in scope.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
interface MigrationAnalyzer
{
    /**
     * @param  list<string>  $paths  Analyse only these directories, ignoring the configured ones.
     */
    public function analyze(MigrationScope $scope = MigrationScope::Pending, array $paths = []): MigrationReport;
}
