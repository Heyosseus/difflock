<?php

declare(strict_types=1);

namespace Difflock\Exceptions;

use RuntimeException;

/**
 * A drift check was asked for, and there is no baseline to check against.
 *
 * Difflock does not silently skip the check. Drift detection compares the live
 * schema against a snapshot somebody deliberately recorded; with no snapshot there
 * is nothing to compare, and a green tick would be a lie.
 */
final class MissingBaseline extends RuntimeException
{
    public static function at(string $path): self
    {
        return new self(
            "No schema baseline at {$path}. Record one with `php artisan difflock:diff --save`."
        );
    }
}
