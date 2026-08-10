<?php

declare(strict_types=1);

use Difflock\Contracts\SchemaDiffer;
use Difflock\Contracts\SchemaInspector;
use Difflock\Schema\Baseline;
use Difflock\Schema\Column;
use Difflock\Schema\DatabaseSchema;
use Difflock\Schema\SchemaSnapshot;
use Difflock\Schema\Table;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('status')->default('draft');
    });

    Schema::create('oauth_access_tokens', function (Blueprint $table): void {
        $table->id();
        $table->string('secret');
    });

    Schema::create('personal_access_tokens', function (Blueprint $table): void {
        $table->id();
    });
});

it('leaves an ignored table out of every inspection', function (): void {
    config()->set('difflock.ignore.tables', ['oauth_*', 'personal_access_tokens']);

    $schema = app(SchemaInspector::class)->inspect();

    expect($schema->tableNames())->toContain('users')
        ->and($schema->tableNames())->not->toContain('oauth_access_tokens')
        ->and($schema->tableNames())->not->toContain('personal_access_tokens');
});

it('never writes an ignored table into the committed baseline', function (): void {
    config()->set('difflock.ignore.tables', ['oauth_*']);

    runCommand('difflock:diff', ['--save' => true]);

    $written = file_get_contents(app(Baseline::class)->path());

    expect($written)->toContain('users')
        ->and($written)->not->toContain('oauth_access_tokens')
        ->and($written)->not->toContain('secret');
});

it('reports no drift for a table it was told to ignore', function (): void {
    config()->set('difflock.ignore.tables', ['oauth_*']);

    runCommand('difflock:diff', ['--save' => true]);

    Schema::table('oauth_access_tokens', fn (Blueprint $table) => $table->string('added')->nullable());

    [$exit, $output] = runCommand('difflock:diff');

    expect($exit)->toBe(0)
        ->and($output)->toContain('No differences');
});

it('records defaults and comments unless told not to', function (): void {
    runCommand('difflock:diff', ['--save' => true]);

    expect(file_get_contents(app(Baseline::class)->path()))->toContain('draft');
});

it('leaves defaults out of the baseline when asked', function (): void {
    config()->set('difflock.snapshot.defaults', false);

    runCommand('difflock:diff', ['--save' => true]);

    expect(file_get_contents(app(Baseline::class)->path()))->not->toContain('draft');
});

/**
 * The property that makes redaction safe to turn on: a snapshot without defaults
 * does not disagree with a live schema that has them. `comparable()` leaves out
 * fields that are null and the comparator only compares fields both sides reported,
 * so the effect is that defaults stop being part of drift — not that every column
 * starts reporting a change.
 */
it('produces no false drift after defaults are redacted', function (): void {
    config()->set('difflock.snapshot.defaults', false);

    runCommand('difflock:diff', ['--save' => true]);

    [$exit, $output] = runCommand('difflock:diff');

    expect($exit)->toBe(0)
        ->and($output)->toContain('No differences');
});

it('redacts on write and leaves the live schema alone', function (): void {
    config()->set('difflock.snapshot.defaults', false);
    config()->set('difflock.snapshot.comments', false);

    runCommand('difflock:diff', ['--save' => true]);

    expect(app(Baseline::class)->read()->table('users')?->column('status')?->default)->toBeNull()
        ->and(app(SchemaInspector::class)->inspect()->table('users')?->column('status')?->default)
        ->toContain('draft');
});

it('redacts a schema without touching anything else about it', function (): void {
    $schema = new DatabaseSchema([
        new Table('users', [
            new Column('status', 'varchar', 'varchar(255)', false, "'draft'", comment: 'a note'),
        ], comment: 'a table note'),
    ], 'pgsql', 'main');

    $kept = $schema->redacted(true, true);
    $redacted = $schema->redacted(false, false);

    expect($kept)->toBe($schema)
        ->and($redacted->table('users')?->column('status')?->default)->toBeNull()
        ->and($redacted->table('users')?->column('status')?->comment)->toBeNull()
        ->and($redacted->table('users')?->comment)->toBeNull()
        ->and($redacted->table('users')?->column('status')?->type)->toBe('varchar')
        ->and($redacted->driver)->toBe('pgsql');
});

it('keeps a redacted snapshot readable and comparable', function (): void {
    $schema = app(SchemaInspector::class)->inspect()->redacted(false, false);

    $decoded = SchemaSnapshot::decode(SchemaSnapshot::encode($schema, '2026-08-11T00:00:00+00:00'), 'test');

    expect(app(SchemaDiffer::class)->diff($decoded, $schema)->isEmpty())->toBeTrue();
});
