<?php

declare(strict_types=1);

namespace Difflock\Mcp;

/**
 * One thing an AI agent can ask Difflock.
 *
 * Tools return plain arrays, which the server encodes. They never write to STDOUT —
 * that channel belongs to the protocol, and a stray `echo` in a tool corrupts the
 * stream for every message after it.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
interface Tool
{
    /** The name the agent calls, snake_cased and prefixed: `difflock_lint_migration`. */
    public function name(): string;

    /**
     * What the tool does, written for a model rather than a person.
     *
     * This is the only thing an agent reads before deciding whether to call it, so it
     * says what the tool answers and when to reach for it — not how it works.
     */
    public function description(): string;

    /**
     * The JSON Schema for the tool's arguments.
     *
     * @return array<string, mixed>
     */
    public function schema(): array;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array;
}
