<?php

declare(strict_types=1);

use Difflock\Checkup;
use Difflock\Contracts\SchemaInspector;
use Difflock\Risk\RiskLevel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** How many queries a callable costs. */
function queriesFor(callable $work): int
{
    $count = 0;

    DB::listen(function () use (&$count): void {
        $count++;
    });

    $work();

    // Laravel keeps every listener registered for the life of the connection, so a
    // second call in one test would be counted twice. Each test measures once.
    return $count;
}

beforeEach(function (): void {
    foreach (['users', 'orders', 'customers', 'invoices', 'payments'] as $name) {
        Schema::create($name, function (Blueprint $table): void {
            $table->id();
            $table->string('label')->nullable();
            $table->index('label');
        });
    }

    config()->set('difflock.migrations.paths', [fixtures('safe')]);
});

/**
 * `difflock:check` used to read the whole schema twice — once for drift, once to
 * give the rules their context. On a 99-table PostgreSQL database that measured 598
 * queries and 3.7 seconds, half of it repeated.
 *
 * The assertion is a ratio rather than a number, because the absolute count depends
 * on the driver and the number of tables. Two full readings would be at least twice
 * one; anything close to a single reading means they are sharing.
 */
it('reads the schema once for a whole check, not once per question', function (): void {
    runCommand('difflock:diff', ['--save' => true]);

    $inspect = queriesFor(fn () => app(SchemaInspector::class)->inspect());

    expect($inspect)->toBeGreaterThan(5);

    $check = queriesFor(fn () => app(Checkup::class)->run(RiskLevel::Critical));

    expect($check)->toBeLessThan($inspect * 2);
});

it('still detects drift while sharing that reading', function (): void {
    runCommand('difflock:diff', ['--save' => true]);

    Schema::table('orders', fn (Blueprint $table) => $table->string('added')->nullable());

    $result = app(Checkup::class)->run(RiskLevel::Critical);

    expect($result->drifted())->toBeTrue()
        ->and($result->drift?->count())->toBe(1)
        ->and($result->failed())->toBeTrue();
});

/**
 * An explicit `--connection` asks about a different database than the one the rules
 * are given, so it cannot share the reading — and must still be correct.
 */
it('reads separately when asked about another connection', function (): void {
    config()->set('database.connections.other', config('database.connections.testing'));

    runCommand('difflock:diff', ['--save' => true]);

    $result = app(Checkup::class)->run(RiskLevel::Critical, 'other');

    expect($result->baselineRecorded)->toBeTrue()
        ->and($result->baselineError)->toBeNull();
});
