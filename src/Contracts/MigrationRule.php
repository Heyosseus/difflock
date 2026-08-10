<?php

declare(strict_types=1);

namespace Difflock\Contracts;

use Difflock\Migration\MigrationContext;
use Difflock\Migration\MigrationFinding;

/**
 * One question asked of one schema statement.
 *
 * Rules know nothing about Artisan, about rendering, or about each other. They are
 * given a {@see MigrationContext} and return findings, which is what lets the same
 * rule run from the CLI, from CI, from the migration guard and from an application's
 * own code without a line of it changing.
 *
 * A rule must not touch the database. Everything it is allowed to know about the
 * live schema and table sizes is already on the context, memoised and shared.
 *
 * Register your own with `Difflock::rule(YourRule::class)` from a service provider,
 * or by adding it to the `rules` array in `config/difflock.php`.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
interface MigrationRule
{
    /**
     * The rule's identifier, kebab-cased — `drop-column`.
     *
     * It appears in every finding, it is what the `ignore` configuration matches
     * against, and it is what a reader searches the documentation for, so it should
     * be stable across versions.
     */
    public function identifier(): string;

    /**
     * @return list<MigrationFinding> Empty when the rule has nothing to say.
     */
    public function analyze(MigrationContext $context): array;
}
