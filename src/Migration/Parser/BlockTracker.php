<?php

declare(strict_types=1);

namespace Difflock\Migration\Parser;

use PhpToken;

/**
 * Keeps track of whether the token currently being read sits somewhere that may not
 * run.
 *
 * A `Schema::dropColumn()` inside an `if` is not the same claim as one at the top
 * level of `up()`, and Difflock refuses to blur the two: the finding it produces
 * says the operation *may* run rather than that it *will*. Working that out means
 * knowing which braces belong to a conditional, which is all this does.
 */
final class BlockTracker
{
    /**
     * Keywords that open a block whose contents may not run.
     *
     * @var list<int>
     */
    private const array CONTROL = [
        T_IF, T_ELSEIF, T_ELSE, T_FOREACH, T_FOR, T_WHILE, T_DO,
        T_SWITCH, T_TRY, T_CATCH, T_FINALLY, T_MATCH,
    ];

    private int $depth = 0;

    /**
     * The brace depths at which a conditional block is currently open.
     *
     * @var list<int>
     */
    private array $conditionals = [];

    /** A control keyword has been seen and its block has not opened yet. */
    private bool $pending = false;

    /**
     * Feed the tracker one token.
     *
     * @return bool Whether the token was structural, and so is not worth examining further.
     */
    public function consume(PhpToken $token): bool
    {
        $text = $token->text;

        if ($text === '{') {
            $this->depth++;

            if ($this->pending) {
                $this->conditionals[] = $this->depth;
                $this->pending = false;
            }

            return true;
        }

        if ($text === '}') {
            if ($this->conditionals !== [] && end($this->conditionals) === $this->depth) {
                array_pop($this->conditionals);
            }

            $this->depth--;

            return true;
        }

        if (in_array($token->id, self::CONTROL, true)) {
            $this->pending = true;

            return true;
        }

        if ($text === ';') {
            // A braceless conditional body — `if ($x) Schema::drop('a');` — ends here,
            // so the next statement is unconditional again.
            $this->pending = false;

            return true;
        }

        return false;
    }

    /** Whether what is being read now might not execute. */
    public function conditional(): bool
    {
        return $this->conditionals !== [] || $this->pending;
    }
}
