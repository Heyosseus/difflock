<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('legacy_token')->nullable();
    });

    Schema::create('orders', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('customer_id');
    });

    config()->set('difflock.migrations.paths', [fixtures()]);
});

/**
 * Everywhere else a summary is right, because the reader is browsing. Here they are
 * not: the guard has just stopped them writing to a database, and the findings that
 * caused it are the entire reason to read the output.
 */
it('prints every blocking finding in full when it blocks', function (): void {
    [$exit, $output] = runCommand('difflock:migrate');

    expect($exit)->toBe(1)
        ->and($output)->toContain('Migration blocked')
        ->toContain('DROP COLUMN users.legacy_token')
        // The explanation, not just the headline — this is the detail view.
        ->toContain('Dropping a column destroys the values in it')
        ->and(Schema::hasColumn('users', 'legacy_token'))->toBeTrue();
});

it('collapses the findings that are not blocking', function (): void {
    [, $output] = runCommand('difflock:migrate');

    expect($output)->toContain('below CRITICAL are not shown')
        ->toContain('difflock:lint');
});

it('summarises rather than expanding when nothing blocks', function (): void {
    [$exit, $output] = runCommand('difflock:migrate', [
        '--dry-run' => true,
        '--path' => [fixtures('safe')],
        '--realpath' => true,
    ]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Dry run')
        ->and($output)->not->toContain('Migration blocked');
});

/**
 * An application whose migrations have all been applied has an empty pending scope.
 * Saying "no migrations were found to analyse" there is false, and the advice that
 * followed it — check your `--path` — sends the reader debugging nothing.
 */
it('says nothing is pending rather than claiming it found no migrations', function (): void {
    runCommand('migrate', ['--path' => [fixtures()], '--realpath' => true, '--force' => true]);

    [, $output] = runCommand('difflock:migrate', ['--dry-run' => true]);

    expect($output)->toContain('No migrations are pending')
        ->and($output)->not->toContain('No migrations were found to analyse')
        ->and($output)->not->toContain('--path');
});

it('does not tell difflock:check to fix its --path either', function (): void {
    runCommand('migrate', ['--path' => [fixtures()], '--realpath' => true, '--force' => true]);
    runCommand('difflock:diff', ['--save' => true]);

    [$exit, $output] = runCommand('difflock:check');

    expect($exit)->toBe(0)
        ->and($output)->toContain('No pending migrations')
        ->and($output)->not->toContain('No migrations were found to analyse');
});
