<?php

declare(strict_types=1);

namespace Difflock\Facades;

use Difflock\Difflock as Manager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Difflock\Schema\DatabaseSchema inspect(?string $connection = null)
 * @method static \Difflock\Diff\SchemaDiff diff(?string $from = null, ?string $to = null)
 * @method static \Difflock\Diff\SchemaDiff drift(?string $connection = null)
 * @method static \Difflock\Schema\DatabaseSchema record(?string $connection = null)
 * @method static \Difflock\Schema\Baseline baseline()
 * @method static \Difflock\Migration\MigrationReport analyze(\Difflock\Migration\MigrationScope $scope = \Difflock\Migration\MigrationScope::Pending)
 * @method static list<\Difflock\Migration\MigrationFinding> lint(\Difflock\Migration\MigrationScope $scope = \Difflock\Migration\MigrationScope::Pending)
 * @method static \Difflock\Protection\GuardDecision guard()
 * @method static Manager rule(string|\Difflock\Contracts\MigrationRule $rule)
 *
 * @see Manager
 */
final class Difflock extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Manager::class;
    }
}
