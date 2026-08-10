<?php

declare(strict_types=1);

namespace Difflock\Diff;

/**
 * What happened to one thing between the two schemas being compared.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
enum ChangeType: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Changed = 'changed';

    /**
     * The marker the console prints, borrowed from `git diff` because everybody
     * already reads it: `+` gained, `-` lost, `~` altered.
     */
    public function marker(): string
    {
        return match ($this) {
            self::Added => '+',
            self::Removed => '-',
            self::Changed => '~',
        };
    }

    public function colour(): string
    {
        return match ($this) {
            self::Added => 'green',
            self::Removed => 'red',
            self::Changed => 'yellow',
        };
    }
}
