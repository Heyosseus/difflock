<?php

declare(strict_types=1);

namespace Difflock\Migration;

use Difflock\Exceptions\InvalidSnapshot;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Date;
use JsonException;

/**
 * The findings an application has looked at and decided to live with.
 *
 * Every static analysis tool meets the same wall on an existing codebase: the first
 * run reports hundreds of true findings about code that already shipped, the build
 * goes red, and within a week somebody raises the threshold until it goes green
 * again. The tool is then installed and worthless.
 *
 * The way out is the one PHPStan and ESLint settled on. Record today's findings as
 * accepted, commit the file, and let the gate fail only on findings that are *new*.
 * The backlog stays visible and countable; it just stops blocking work it cannot
 * retroactively prevent.
 *
 * Two properties make the file trustworthy. Findings are matched by
 * {@see MigrationFinding::fingerprint()}, which ignores line numbers and wording, so
 * reformatting a migration does not resurrect its findings. And acceptance never
 * hides a *new* finding: a rule firing on a migration nobody accepted still fails
 * the build, which is the whole point.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final class AcceptedFindings
{
    /** Bumped only when the on-disk shape changes incompatibly. */
    public const int VERSION = 1;

    /** @var list<string>|null */
    private ?array $fingerprints = null;

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $path,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return $this->files->isFile($this->path);
    }

    /** Whether this finding has already been accepted. */
    public function accepts(MigrationFinding $finding): bool
    {
        return in_array($finding->fingerprint(), $this->fingerprints(), true);
    }

    /**
     * The accepted fingerprints, read once.
     *
     * A file that cannot be parsed accepts nothing. Silently accepting everything
     * because the file is corrupt would turn a broken gate into a green one, which
     * is the failure this whole package exists to argue against.
     *
     * @return list<string>
     */
    public function fingerprints(): array
    {
        return $this->fingerprints ??= $this->read();
    }

    /**
     * @param  list<MigrationFinding>  $findings
     * @return int How many distinct findings were recorded.
     */
    public function write(array $findings): int
    {
        $fingerprints = [];

        foreach ($findings as $finding) {
            $fingerprints[$finding->fingerprint()] = true;
        }

        $accepted = array_keys($fingerprints);
        sort($accepted);

        $this->files->ensureDirectoryExists(dirname($this->path));

        $this->files->put($this->path, json_encode([
            'difflock' => self::VERSION,
            'generated_at' => Date::now()->toIso8601String(),
            'accepted' => $accepted,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        $this->fingerprints = $accepted;

        return count($accepted);
    }

    /**
     * @return list<string>
     */
    private function read(): array
    {
        if (! $this->exists()) {
            return [];
        }

        try {
            $decoded = json_decode($this->files->get($this->path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidSnapshot::at($this->path, $exception->getMessage());
        }

        if (! is_array($decoded) || ! isset($decoded['accepted']) || ! is_array($decoded['accepted'])) {
            throw InvalidSnapshot::at($this->path, 'it has no accepted findings in it');
        }

        $fingerprints = [];

        foreach ($decoded['accepted'] as $fingerprint) {
            if (is_string($fingerprint) && $fingerprint !== '') {
                $fingerprints[] = $fingerprint;
            }
        }

        return $fingerprints;
    }
}
