<?php

declare(strict_types=1);

namespace Difflock\Migration\Parser;

use Stringable;

/**
 * An argument the parser read but could not reduce to a value.
 *
 * `$table->dropColumn($column)` is a drop of *something*, and Difflock says so — it
 * just cannot say what. This sentinel is how that distinction survives: a rule sees
 * an argument it cannot name rather than a null it might mistake for an absent one,
 * and the finding it produces says "a column this analysis could not resolve"
 * instead of inventing a name.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class Unresolved implements Stringable
{
    /** @param  string  $expression  The source text, trimmed, for showing the reader. */
    public function __construct(public string $expression = '') {}

    public function __toString(): string
    {
        return $this->expression === '' ? '<unresolved>' : $this->expression;
    }
}
