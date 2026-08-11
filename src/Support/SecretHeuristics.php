<?php

declare(strict_types=1);

namespace Difflock\Support;

use Difflock\Schema\Column;
use Difflock\Schema\DatabaseSchema;

/**
 * Column defaults that look like they might be credentials.
 *
 * The baseline is the one file Difflock asks you to commit, and a column default is
 * the one place in a schema where an arbitrary string ends up. Most of them are
 * `'draft'` or `0`. Occasionally one is a connection string with a password in it,
 * or an API key somebody set as a default "temporarily" in 2023 — and committing
 * that puts it in git history permanently, where deleting it later does not remove
 * it.
 *
 * ## Heuristics, and their limits
 *
 * This recognises *shapes*, not secrets. It cannot know whether a long hex string is
 * a key or a checksum, and it will never catch a credential that looks like an
 * ordinary word. It is a prompt before a one-way action, not a scanner, and it is
 * deliberately tuned to stay quiet: a false positive on every `difflock:diff --save`
 * would train people to ignore the one that mattered.
 *
 * Function-call defaults are skipped entirely. `nextval(...)`, `gen_random_uuid()`
 * and `CURRENT_TIMESTAMP` are the overwhelming majority of exotic-looking defaults
 * and none of them can carry a secret.
 */
final class SecretHeuristics
{
    /**
     * Prefixes that identify a credential on sight, published by the services that
     * issue them precisely so they can be recognised.
     *
     * @var array<string, string>
     */
    private const array PREFIXES = [
        '-----BEGIN' => 'a PEM-encoded private key',
        'sk_live_' => 'a live Stripe secret key',
        'sk_test_' => 'a Stripe test secret key',
        'rk_live_' => 'a live Stripe restricted key',
        'ghp_' => 'a GitHub personal access token',
        'gho_' => 'a GitHub OAuth token',
        'github_pat_' => 'a GitHub personal access token',
        'glpat-' => 'a GitLab personal access token',
        'xoxb-' => 'a Slack bot token',
        'xoxp-' => 'a Slack user token',
        'AKIA' => 'an AWS access key id',
        'ASIA' => 'an AWS temporary access key id',
        'AIza' => 'a Google API key',
        'SG.' => 'a SendGrid API key',
    ];

    /**
     * Every column default in the schema that looks like it should not be published.
     *
     * @return list<array{table: string, column: string, reason: string}>
     */
    public static function suspects(DatabaseSchema $schema): array
    {
        $suspects = [];

        foreach ($schema->tables as $table) {
            foreach ($table->columns as $column) {
                $reason = self::reason($column);

                if ($reason !== null) {
                    $suspects[] = ['table' => $table->name, 'column' => $column->name, 'reason' => $reason];
                }
            }
        }

        return $suspects;
    }

    /** Why this column's default looks like a credential, or null if it does not. */
    public static function reason(Column $column): ?string
    {
        $value = self::normalise($column->default);

        if ($value === null) {
            return null;
        }

        foreach (self::PREFIXES as $prefix => $describes) {
            if (str_starts_with($value, $prefix)) {
                return $describes;
            }
        }

        if (self::hasCredentialsInUrl($value)) {
            return 'a URL with a username and password in it';
        }

        if (preg_match('/\b(password|passwd|secret|api[_-]?key|access[_-]?token)\s*=\s*\S/i', $value) === 1) {
            return 'a connection string or query carrying a credential';
        }

        if (self::looksRandom($value)) {
            return 'a long random-looking string';
        }

        return null;
    }

    /**
     * The default as the value itself: quotes removed, and any driver type cast
     * dropped from the end.
     *
     * PostgreSQL reports `'draft'::character varying`, MySQL reports `draft`. Both
     * reduce to `draft`, and a default that is a function call reduces to nothing —
     * `now()` cannot hold a secret and neither can `nextval('users_id_seq')`.
     */
    private static function normalise(?string $default): ?string
    {
        if ($default === null) {
            return null;
        }

        $value = trim($default);

        // Drop a trailing PostgreSQL cast before anything else, so the quotes it sits
        // outside of can still be stripped.
        $value = (string) preg_replace('/::[a-z0-9_ ]+$/i', '', $value);
        $value = trim($value);

        if (strlen($value) >= 2 && ($value[0] === "'" || $value[0] === '"') && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }

        // A function call, not a literal.
        if (str_contains($value, '(')) {
            return null;
        }

        return $value === '' ? null : $value;
    }

    private static function hasCredentialsInUrl(string $value): bool
    {
        return preg_match('#^[a-z][a-z0-9+.-]*://[^/\s:@]+:[^/\s@]+@#i', $value) === 1;
    }

    /**
     * Whether the value looks like it was generated rather than typed.
     *
     * Long, unbroken, and drawn from an alphabet a person would not choose for a
     * default. The length floor is what keeps `draft`, `pending` and `en_GB` out,
     * and the "no spaces" requirement keeps sentences out.
     */
    private static function looksRandom(string $value): bool
    {
        if (strlen($value) < 32 || str_contains($value, ' ')) {
            return false;
        }

        // Hex or base64/base64url, entirely — the shape of a generated key.
        return preg_match('/^[a-f0-9]{32,}$/i', $value) === 1
            || preg_match('/^[A-Za-z0-9+\/_-]{32,}={0,2}$/', $value) === 1;
    }

    /**
     * @param  list<array{table: string, column: string, reason: string}>  $suspects
     * @return list<string>
     */
    public static function describe(array $suspects): array
    {
        return array_map(
            static fn (array $suspect): string => $suspect['table'].'.'.$suspect['column'].' — '.$suspect['reason'],
            $suspects,
        );
    }
}
