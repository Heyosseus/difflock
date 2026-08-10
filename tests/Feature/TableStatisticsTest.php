<?php

declare(strict_types=1);

use Difflock\Contracts\TableStatistics;
use Difflock\Database\ConnectionTableStatistics;
use Difflock\Database\FixedTableStatistics;
use Difflock\Database\NullTableStatistics;
use Difflock\Migration\DatabaseContext;
use Difflock\Schema\DatabaseSchema;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function statistics(): TableStatistics
{
    return new ConnectionTableStatistics(app(ConnectionResolverInterface::class));
}

beforeEach(function (): void {
    Schema::create('orders', function (Blueprint $table): void {
        $table->id();
    });
});

it('counts rows on SQLite, which keeps no estimate of its own', function (): void {
    expect(statistics()->rows('orders'))->toBe(0)
        ->and(statistics()->approximate())->toBeFalse();

    DB::table('orders')->insert([['id' => 1], ['id' => 2]]);

    expect(statistics()->rows('orders'))->toBe(2);
});

it('answers unknown for a table that is not there, and for sizes SQLite does not keep', function (): void {
    expect(statistics()->rows('nothing_of_the_sort'))->toBeNull()
        ->and(statistics()->bytes('orders'))->toBeNull();
});

it('answers unknown for a connection it cannot reach', function (): void {
    config()->set('database.connections.broken', ['driver' => 'sqlite', 'database' => '/no/such/file.sqlite']);

    $statistics = new ConnectionTableStatistics(app(ConnectionResolverInterface::class), 'broken');

    expect($statistics->rows('orders'))->toBeNull()
        ->and($statistics->bytes('orders'))->toBeNull();
});

it('answers unknown for everything when there is nothing to ask', function (): void {
    $statistics = new NullTableStatistics;

    expect($statistics->rows('orders'))->toBeNull()
        ->and($statistics->bytes('orders'))->toBeNull()
        ->and($statistics->approximate())->toBeFalse();
});

it('lets a test supply sizes by hand', function (): void {
    $statistics = new FixedTableStatistics(['orders' => 8_421_392], ['orders' => 1024], true);

    expect($statistics->rows('orders'))->toBe(8_421_392)
        ->and($statistics->bytes('orders'))->toBe(1024)
        ->and($statistics->rows('users'))->toBeNull()
        ->and($statistics->approximate())->toBeTrue();
});

it('describes a size only when it knows one, and hedges an estimate', function (): void {
    $exact = new DatabaseContext(
        new DatabaseSchema,
        new FixedTableStatistics(['orders' => 1, 'users' => 4_921_000]),
    );

    $estimated = new DatabaseContext(
        new DatabaseSchema,
        new FixedTableStatistics(['orders' => 4_921_000], approximate: true),
    );

    expect($exact->describeSize('orders'))->toBe('1 row')
        ->and($exact->describeSize('users'))->toBe('4,921,000 rows')
        ->and($exact->describeSize('nothing'))->toBeNull()
        ->and($exact->describeSize(null))->toBeNull()
        ->and($estimated->describeSize('orders'))->toBe('roughly 4,921,000 rows');
});

it('answers unknown for everything when the database was unreachable', function (): void {
    $context = new DatabaseContext(
        schema: new DatabaseSchema,
        statistics: new FixedTableStatistics(['orders' => 100]),
        available: false,
    );

    expect($context->rows('orders'))->toBeNull()
        ->and($context->bytes('orders'))->toBeNull()
        ->and($context->describeSize('orders'))->toBeNull()
        ->and($context->hasTable('orders'))->toBeFalse()
        ->and($context->table(null))->toBeNull();
});

it('knows which environment it is running in', function (): void {
    expect((new DatabaseContext(new DatabaseSchema, new NullTableStatistics, environment: 'production'))->isProduction())
        ->toBeTrue()
        ->and((new DatabaseContext(new DatabaseSchema, new NullTableStatistics, environment: 'local'))->isProduction())
        ->toBeFalse();
});

it('reports the driver and version of the database it inspected', function (): void {
    $context = app(Difflock\Database\DatabaseContextFactory::class)->make();

    expect($context->available)->toBeTrue()
        ->and($context->driver())->toBe('sqlite')
        ->and($context->version)->not->toBeEmpty()
        ->and($context->environment)->toBe('testing');
});

it('reports an unreachable database as unavailable rather than as empty', function (): void {
    config()->set('database.connections.broken', ['driver' => 'sqlite', 'database' => '/no/such/file.sqlite']);
    config()->set('difflock.connection', 'broken');

    $context = app(Difflock\Database\DatabaseContextFactory::class)->make();

    expect($context->available)->toBeFalse()
        ->and($context->schema->tables)->toBeEmpty()
        ->and($context->rows('orders'))->toBeNull();
});
