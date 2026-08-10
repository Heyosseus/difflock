<?php

declare(strict_types=1);

namespace Difflock\Console\Commands\Concerns;

use Difflock\Console\Formatters\JsonReport;
use Difflock\Risk\RiskLevel;
use Illuminate\Contracts\Config\Repository;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The handful of decisions every Difflock command makes the same way: is the package
 * switched on, what output was asked for, and where is the bar.
 *
 * @mixin \Illuminate\Console\Command
 */
trait InteractsWithDifflock
{
    /**
     * Whether Difflock is enabled.
     *
     * A disabled Difflock runs no queries and reads no migrations, so it would find
     * nothing — and a check that goes green because it never looked is worse than no
     * check at all. Commands refuse rather than pass.
     */
    protected function enabled(Repository $config): bool
    {
        if ($config->get('difflock.enabled') !== false) {
            return true;
        }

        $this->components->error(
            'Difflock is disabled, so nothing was checked. Set DIFFLOCK_ENABLED=true to run this.',
        );

        return false;
    }

    /**
     * The risk level at or above which the command fails, from `--fail-on` or config.
     *
     * Null means the option named a level that does not exist, and the caller should
     * exit with the configuration-error code rather than guessing what was meant.
     */
    protected function threshold(Repository $config): ?RiskLevel
    {
        $option = $this->option('fail-on');

        $value = is_string($option) && $option !== ''
            ? $option
            : $config->get('difflock.risk.fail_on', 'critical');

        if (! is_string($value)) {
            return null;
        }

        return RiskLevel::tryFrom(strtolower($value));
    }

    protected function unknownThreshold(): int
    {
        $this->components->error(
            'There is no risk level called \''.(is_scalar($this->option('fail-on')) ? (string) $this->option('fail-on') : '')
                .'\'. Use safe, low, medium, high or critical.',
        );

        return self::INVALID;
    }

    /** Whether the caller asked for JSON. */
    protected function wantsJson(): bool
    {
        return $this->option('format') === 'json';
    }

    /**
     * Write a JSON document, with nothing else on the stream.
     *
     * `OUTPUT_RAW` is what keeps the promise: Symfony neither interprets style tags
     * nor writes any of its own, so the document survives being piped into `jq`
     * whether or not the terminal is a TTY.
     *
     * @param  array<string, mixed>  $document
     */
    protected function writeJson(array $document): void
    {
        $this->output->writeln(JsonReport::encode($document), OutputInterface::OUTPUT_RAW);
    }

    /**
     * @return list<string>
     */
    protected function paths(): array
    {
        $option = $this->option('path');

        if (! is_array($option)) {
            return [];
        }

        $paths = [];

        foreach ($option as $path) {
            if (is_string($path) && $path !== '') {
                $paths[] = $this->absolute($path);
            }
        }

        return $paths;
    }

    /**
     * A path as given if it is already absolute, and relative to the application root
     * otherwise.
     *
     * This follows Laravel's own `--path` / `--realpath` convention so that the same
     * arguments mean the same thing to `difflock:migrate` and to `migrate`. It is
     * kinder than Laravel about one case: on Windows an absolute path starts with a
     * drive letter rather than a slash, and prefixing the base path onto `C:\...`
     * produces something that exists nowhere.
     */
    protected function absolute(string $path): string
    {
        if ($this->hasOption('realpath') && $this->option('realpath') === true) {
            return $path;
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return $path;
        }

        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return $this->laravel->basePath($path);
    }
}
