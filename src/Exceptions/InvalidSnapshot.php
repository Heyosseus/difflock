<?php

declare(strict_types=1);

namespace Difflock\Exceptions;

use RuntimeException;

/**
 * A baseline file that is not a schema snapshot Difflock wrote.
 *
 * Thrown rather than recovered from: a snapshot that cannot be read is not an empty
 * schema, and treating it as one would report every table in the database as newly
 * added. A drift check that cannot read its baseline has failed, not passed.
 */
final class InvalidSnapshot extends RuntimeException
{
    public static function at(string $path, string $because): self
    {
        return new self("The schema snapshot at {$path} could not be read: {$because}");
    }
}
