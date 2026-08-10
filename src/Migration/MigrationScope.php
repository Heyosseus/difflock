<?php

declare(strict_types=1);

namespace Difflock\Migration;

/**
 * Which migrations an analysis covers.
 *
 * `Pending` is the default everywhere, because it is the only one that answers the
 * question people actually have: *is it safe to deploy this?* Linting migrations
 * that already ran cannot change anything, and a build that fails over a drop
 * committed two years ago is a build nobody will keep green.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
enum MigrationScope: string
{
    /** Migrations the repository has no record of having run. */
    case Pending = 'pending';

    /** Every migration file found, run or not. */
    case All = 'all';
}
