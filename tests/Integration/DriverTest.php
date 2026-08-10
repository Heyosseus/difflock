<?php

declare(strict_types=1);

use Difflock\Contracts\MigrationAnalyzer;
use Difflock\Contracts\SchemaDiffer;
use Difflock\Contracts\SchemaInspector;
use Difflock\Database\ConnectionTableStatistics;
use Difflock\Diff\ChangeType;
use Difflock\Migration\MigrationScope;
use Difflock\Tests\IntegrationTestCase;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(IntegrationTestCase::class);

beforeEach(function (): void {
    if (IntegrationTestCase::driver() === null) {
        $this->markTestSkipped('Set DIFFLOCK_DB_DRIVER to run the driver integration tests.');
    }

    Schema::dropIfExists('difflock_orders');
    Schema::dropIfExists('difflock_customers');

    Schema::create('difflock_customers', function (Blueprint $table): void {
        $table->id();
        $table->string('email', 320)->unique();
    });

    Schema::create('difflock_orders', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('customer_id')->constrained('difflock_customers');
        $table->decimal('total', 10, 2);
        $table->string('status', 40)->nullable()->default('draft');
        $table->index('status');
    });
});

afterEach(function (): void {
    if (IntegrationTestCase::driver() !== null) {
        Schema::dropIfExists('difflock_orders');
        Schema::dropIfExists('difflock_customers');
    }
});

it('reads lengths, precision and scale from a driver that stores them', function (): void {
    $orders = app(SchemaInspector::class)->inspect()->table('difflock_orders');

    expect($orders?->column('status')?->length)->toBe(40)
        ->and($orders?->column('total')?->precision)->toBe(10)
        ->and($orders?->column('total')?->scale)->toBe(2)
        ->and($orders?->column('status')?->nullable)->toBeTrue()
        ->and($orders?->column('id')?->autoIncrement)->toBeTrue();
});

it('reads indexes and foreign keys with the names the server gave them', function (): void {
    $schema = app(SchemaInspector::class)->inspect();
    $orders = $schema->table('difflock_orders');

    expect($orders?->indexOn(['status']))->not->toBeNull()
        ->and($schema->table('difflock_customers')?->indexOn(['email'])?->unique)->toBeTrue();

    $key = array_values($orders?->foreignKeys ?? [])[0];

    expect($key->foreignTable)->toBe('difflock_customers')
        ->and($key->columns)->toBe(['customer_id'])
        ->and($key->foreignColumns)->toBe(['id']);
});

it('reports unsigned only on the drivers that have the concept', function (): void {
    $unsigned = app(SchemaInspector::class)->inspect()->table('difflock_orders')?->column('id')?->unsigned;

    expect($unsigned)->toBe(in_array(IntegrationTestCase::driver(), ['mysql', 'mariadb'], true) ? true : null);
});

it('estimates row counts and table sizes from database metadata', function (): void {
    DB::table('difflock_customers')->insert([['email' => 'a@example.test'], ['email' => 'b@example.test']]);

    $statistics = new ConnectionTableStatistics(app(ConnectionResolverInterface::class));

    expect($statistics->approximate())->toBeTrue()
        ->and($statistics->bytes('difflock_customers'))->toBeGreaterThanOrEqual(0);

    // The row count is an estimate the server may not have refreshed yet, so this
    // asserts only that the driver answered with something usable — never that the
    // estimate is exact, which it is not and does not claim to be.
    $rows = $statistics->rows('difflock_customers');

    expect($rows === null || $rows >= 0)->toBeTrue();
});

it('diffs a real schema against a snapshot of itself and finds nothing', function (): void {
    $before = app(SchemaInspector::class)->inspect();

    expect(app(SchemaDiffer::class)->diff($before, $before)->isEmpty())->toBeTrue();
});

it('finds a real column added to a real table', function (): void {
    $before = app(SchemaInspector::class)->inspect();

    Schema::table('difflock_orders', fn (Blueprint $table) => $table->string('phone', 50)->nullable());

    $after = app(SchemaInspector::class)->inspect();
    $diff = app(SchemaDiffer::class)->diff($before, $after);

    $table = collect($diff->tables)->firstWhere('name', 'difflock_orders');

    expect($table?->type)->toBe(ChangeType::Changed)
        ->and($table?->columns[0]->name)->toBe('phone')
        ->and($table?->columns[0]->type)->toBe(ChangeType::Added);
});

it('analyses migrations against a real database', function (): void {
    config()->set('difflock.migrations.paths', [fixtures('safe')]);

    $report = app(MigrationAnalyzer::class)->analyze(MigrationScope::Pending);

    expect($report->databaseAvailable)->toBeTrue()
        ->and($report->migrations)->toHaveCount(1);
});
