<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('users', fn (Blueprint $table) => $table->id());

    config()->set('difflock.migrations.paths', [fixtures()]);
});

it('reports the ground every other command stands on', function (): void {
    [$exit, $output] = runCommand('difflock:doctor');

    expect($exit)->toBe(0)
        ->and($output)->toContain('Difflock  ·  Doctor')
        ->toContain('Database')
        ->toContain('sqlite')
        ->toContain('Privileges')
        ->toContain('Migrations')
        ->toContain('Rules')
        ->toContain('drop-column')
        ->toContain('Files');
});

it('says whether the role could write, whatever the answer', function (): void {
    [, $output] = runCommand('difflock:doctor');

    expect($output)->toContain('Privileges')
        ->and($output)->toMatch('/(cannot write|is able to write|could not be determined)/');
});

it('counts the tables and migrations it can see', function (): void {
    [, $output] = runCommand('difflock:doctor', ['--format' => 'json']);

    $document = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);

    expect($document['reachable'])->toBeTrue()
        ->and($document['driver'])->toBe('sqlite')
        ->and($document['tables'])->toBeGreaterThan(0)
        ->and($document['all_migrations'])->toBe(3)
        ->and($document['rules'])->toContain('drop-column', 'sensitive-column', 'unindexed-foreign-key')
        ->and($document['enabled'])->toBeTrue();
});

it('reports where the baseline and accepted files live and whether they exist', function (): void {
    [, $output] = runCommand('difflock:doctor', ['--format' => 'json']);

    $document = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);

    expect($document['baseline']['recorded'])->toBeFalse()
        ->and($document['accepted']['recorded'])->toBeFalse()
        ->and($document['baseline']['path'])->toContain('schema.json');

    runCommand('difflock:diff', ['--save' => true]);

    [, $output] = runCommand('difflock:doctor', ['--format' => 'json']);

    expect(json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR)['baseline']['recorded'])->toBeTrue();
});

it('fails when the database cannot be reached, rather than reporting an empty one', function (): void {
    config()->set('database.connections.broken', ['driver' => 'sqlite', 'database' => '/no/such/file.sqlite']);
    config()->set('difflock.connection', 'broken');

    [$exit, $output] = runCommand('difflock:doctor');

    expect($exit)->toBe(2)
        ->and($output)->toContain('Reachable')
        ->toContain('no');
});

/**
 * The probe opens a transaction, tries the cheapest write there is, and rolls back
 * on both paths. If it ever leaked, this table would survive it.
 */
it('leaves nothing behind when it probes for write access', function (): void {
    runCommand('difflock:doctor');

    expect(Schema::hasTable('difflock_write_probe'))->toBeFalse();
});

it('emits JSON with no ANSI in it', function (): void {
    [, $output] = runCommand('difflock:doctor', ['--format' => 'json'], decorated: true);

    expect($output)->not->toContain("\033")
        ->and(json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('difflock');
});
