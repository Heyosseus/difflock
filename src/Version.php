<?php

declare(strict_types=1);

namespace Difflock;

/**
 * The package's own version, for the handful of places something has to say it.
 *
 * Kept here rather than read from `composer.json` because an installed package's
 * version lives in the *root* application's lock file, not in its own manifest, and
 * reading it back out is more machinery than a string is worth.
 */
final class Version
{
    public const string CURRENT = '0.5.0';
}
