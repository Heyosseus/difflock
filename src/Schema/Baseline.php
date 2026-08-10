<?php

declare(strict_types=1);

namespace Difflock\Schema;

use Difflock\Exceptions\MissingBaseline;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Date;

/**
 * The recorded schema that drift is measured against.
 *
 * A committed baseline turns a vague question — "has this database drifted?" — into
 * one with an answer: does it still match the schema we agreed on and checked in?
 * Recording it is a deliberate act, which is why Difflock refuses to invent one:
 * a drift check with nothing to compare against fails rather than passing.
 */
final readonly class Baseline
{
    public function __construct(
        private Filesystem $files,
        private string $path,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return $this->files->isFile($this->path);
    }

    /**
     * @throws MissingBaseline When no baseline has been recorded.
     */
    public function read(): DatabaseSchema
    {
        if (! $this->exists()) {
            throw MissingBaseline::at($this->path);
        }

        return SchemaSnapshot::decode($this->files->get($this->path), $this->path);
    }

    public function write(DatabaseSchema $schema): void
    {
        $this->files->ensureDirectoryExists(dirname($this->path));

        $this->files->put($this->path, SchemaSnapshot::encode($schema, Date::now()->toIso8601String()));
    }
}
