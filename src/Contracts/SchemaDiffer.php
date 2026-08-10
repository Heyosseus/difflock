<?php

declare(strict_types=1);

namespace Difflock\Contracts;

use Difflock\Diff\SchemaDiff;
use Difflock\Schema\DatabaseSchema;

/**
 * Compares two concrete schemas.
 *
 * Both sides are schemas that were actually observed — a live connection, or a
 * snapshot taken from one. Difflock never asks a differ to compare a real schema
 * against one reconstructed from migration source, because migrations are arbitrary
 * PHP and that reconstruction cannot be made reliable. See the README's Limitations.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
interface SchemaDiffer
{
    public function diff(DatabaseSchema $from, DatabaseSchema $to): SchemaDiff;
}
