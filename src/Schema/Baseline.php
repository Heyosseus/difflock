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
 *
 * Because the file is meant to be committed, it is also the one thing Difflock
 * publishes. Column defaults and comments are the only fields in a schema that carry
 * somebody's own words, and an application can decline to record them.
 *
 * Redaction happens on write and nowhere else, which is what keeps it free of side
 * effects. {@see Column::comparable()} leaves out fields that are null, and the
 * comparator only compares fields both sides reported — so a baseline recorded
 * without defaults produces no false differences against a live schema that has
 * them. It simply stops defaults being part of drift detection, and the rules, which
 * read the live schema rather than the baseline, are unaffected either way.
 */
final readonly class Baseline
{
    public function __construct(
        private Filesystem $files,
        private string $path,
        private bool $recordDefaults = true,
        private bool $recordComments = true,
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

        $this->files->put($this->path, SchemaSnapshot::encode(
            $schema->redacted($this->recordDefaults, $this->recordComments),
            Date::now()->toIso8601String(),
        ));
    }
}
