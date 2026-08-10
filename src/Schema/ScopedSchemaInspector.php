<?php

declare(strict_types=1);

namespace Difflock\Schema;

use Difflock\Contracts\SchemaInspector;
use Illuminate\Support\Str;

/**
 * An inspector that leaves out the tables the application asked Difflock to ignore.
 *
 * This wraps the real inspector rather than living inside it, so there is exactly
 * one place where "which tables are Difflock's business" is decided, and every
 * consumer — the differ, the baseline, the rules, the facade — is downstream of it.
 * The alternative was filtering at each call site, which works until somebody adds
 * the seventh call site and forgets.
 *
 * It matters for more than tidiness. `difflock:diff --save` writes the result of an
 * inspection to a file people are told to commit, so a table excluded here is a
 * table that never reaches git. That is the control an application needs when its
 * schema contains something it would rather not publish — and without it, `ignore`
 * suppressed findings while the snapshot recorded the table anyway.
 *
 * Patterns accept `*` wildcards, matched with Laravel's own `Str::is`.
 */
final readonly class ScopedSchemaInspector implements SchemaInspector
{
    /**
     * @param  list<string>  $ignored  Table name patterns to leave out entirely.
     */
    public function __construct(
        private SchemaInspector $inner,
        private array $ignored = [],
    ) {}

    public function inspect(?string $connection = null): DatabaseSchema
    {
        $schema = $this->inner->inspect($connection);

        if ($this->ignored === []) {
            return $schema;
        }

        $drop = [];

        foreach ($schema->tableNames() as $name) {
            if (Str::is($this->ignored, $name)) {
                $drop[] = $name;
            }
        }

        return $drop === [] ? $schema : $schema->without($drop);
    }
}
