<?php

declare(strict_types=1);

namespace Difflock\Migration;

use Illuminate\Support\Str;

/**
 * What the application has asked Difflock to stay quiet about.
 *
 * Filtering happens on the finding, after the rules have run, rather than by not
 * running rules at all. That costs nothing measurable and it keeps one property
 * worth having: the ignore list can only ever remove findings, so a mistake in it
 * cannot make a rule report something different from what it would have reported.
 *
 * Every pattern accepts `*` wildcards, matched with Laravel's own `Str::is`.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class IgnoreList
{
    /**
     * @param  list<string>  $rules  Rule identifiers: `add-index`, `drop-*`.
     * @param  list<string>  $tables  Table names: `telescope_*`, `jobs`.
     * @param  list<string>  $migrations  Migration names: `2019_*`.
     */
    public function __construct(
        public array $rules = [],
        public array $tables = [],
        public array $migrations = [],
    ) {}

    /** Whether the finding survives the ignore list. */
    public function allows(MigrationFinding $finding): bool
    {
        if (Str::is($this->rules, $finding->rule)) {
            return false;
        }

        if ($finding->table !== null && Str::is($this->tables, $finding->table)) {
            return false;
        }

        return ! Str::is($this->migrations, $finding->migration);
    }

    /** Whether this table is ignored, used to skip drift reporting as well as findings. */
    public function ignoresTable(string $table): bool
    {
        return Str::is($this->tables, $table);
    }

    /**
     * @param  array<mixed>  $config  The `ignore` section of the configuration.
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            self::strings($config, 'rules'),
            self::strings($config, 'tables'),
            self::strings($config, 'migrations'),
        );
    }

    /**
     * @param  array<mixed>  $config
     * @return list<string>
     */
    private static function strings(array $config, string $key): array
    {
        $values = $config[$key] ?? [];

        if (! is_array($values)) {
            return [];
        }

        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $strings[] = $value;
            }
        }

        return $strings;
    }
}
