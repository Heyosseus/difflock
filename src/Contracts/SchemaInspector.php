<?php

declare(strict_types=1);

namespace Difflock\Contracts;

use Difflock\Schema\DatabaseSchema;

/**
 * Reads a database's actual structure.
 *
 * This is the only thing in Difflock that knows what a schema really looks like.
 * Everything else — the differ, the rules, the guard — works from what an inspector
 * returned, which is what lets the same analysis run against a live connection, a
 * saved snapshot, or a schema a test built by hand.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
interface SchemaInspector
{
    /**
     * @param  string|null  $connection  Null uses the connection Difflock is configured with.
     */
    public function inspect(?string $connection = null): DatabaseSchema;
}
