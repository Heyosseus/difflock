<?php

declare(strict_types=1);

namespace Difflock\Risk;

/**
 * How much attention a schema change deserves before it reaches production.
 *
 * The levels are deterministic: every built-in rule documents the conditions under
 * which it returns each one, and the same migration analysed against the same
 * database always produces the same level. Nothing here is scored, weighted or
 * guessed — a level is the name of a branch a rule took, not a number a model
 * produced.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
enum RiskLevel: string
{
    /** Nothing about this operation can lose data or break a running application. */
    case Safe = 'safe';

    /** Worth knowing. Reversible, and unlikely to be felt by anybody. */
    case Low = 'low';

    /** Reversible, but capable of causing noticeable impact on a busy table. */
    case Medium = 'medium';

    /** Capable of breaking the running application, or of failing partway through. */
    case High = 'high';

    /** Destroys data or structure that no `down()` can bring back. */
    case Critical = 'critical';

    /**
     * The level's position in the ordering, ascending with severity.
     *
     * Comparing `rank()` rather than the enum instances means a threshold reads
     * the same way everywhere: at or above the bar fails.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Safe => 0,
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }

    /** Whether this level is at least as serious as the given one. */
    public function atLeast(self $level): bool
    {
        return $this->rank() >= $level->rank();
    }

    /** The more serious of the two. */
    public function max(self $other): self
    {
        return $this->rank() >= $other->rank() ? $this : $other;
    }

    /** The level's name as it appears in the console, upper-cased and padded by the caller. */
    public function label(): string
    {
        return strtoupper($this->value);
    }

    /** The colour the console renders this level in. */
    public function colour(): string
    {
        return match ($this) {
            self::Safe => 'green',
            self::Low => 'blue',
            self::Medium => 'yellow',
            self::High => 'bright-red',
            self::Critical => 'red',
        };
    }

    /** The glyph the console marks this level with, chosen to read without colour. */
    public function glyph(): string
    {
        return match ($this) {
            self::Safe => '✓',
            self::Low, self::Medium => '⚠',
            self::High, self::Critical => '✗',
        };
    }

    /**
     * Every level, ordered from safest to most serious.
     *
     * @return list<self>
     */
    public static function ascending(): array
    {
        return [self::Safe, self::Low, self::Medium, self::High, self::Critical];
    }
}
