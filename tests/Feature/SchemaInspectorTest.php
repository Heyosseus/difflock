<?php

declare(strict_types=1);

use Difflock\Contracts\SchemaDiffer;
use Difflock\Contracts\SchemaInspector;
use Difflock\Schema\Baseline;
use Difflock\Schema\SchemaSnapshot;
use Difflock\Support\TypeDefinition;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('customers', function (Blueprint $table): void {
        $table->id();
        $table->string('email', 320)->unique();
    });

    Schema::create('orders', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('customer_id')->constrained();
        $table->decimal('total', 10, 2);
        $table->string('status')->nullable()->default('draft');
        $table->index('status');
    });
});

it('reads tables, columns, indexes and foreign keys from a live connection', function (): void {
    $schema = app(SchemaInspector::class)->inspect();

    expect($schema->tableNames())->toContain('customers', 'orders')
        ->and($schema->driver)->toBe('sqlite');

    $orders = $schema->table('orders');

    expect($orders?->hasColumn('customer_id'))->toBeTrue()
        ->and($orders?->column('status')?->nullable)->toBeTrue()
        ->and($orders?->column('status')?->default)->toContain('draft')
        ->and($orders?->column('total')?->type)->toBe('numeric')
        ->and($orders?->column('id')?->autoIncrement)->toBeTrue()
        ->and($orders?->indexOn(['status']))->not->toBeNull()
        ->and($orders?->foreignKeys)->not->toBeEmpty();

    $key = array_values($orders?->foreignKeys ?? [])[0];

    expect($key->foreignTable)->toBe('customers')
        ->and($key->columns)->toBe(['customer_id']);
});

/**
 * SQLite is not being worked around here — it genuinely does not store the length.
 * Laravel's SQLite grammar emits `varchar` for `string('email', 320)`, and
 * `pragma table_info` has nothing more to give back. Difflock reports null, the
 * comparison layer reads null as "not comparable", and no diff ever claims a length
 * changed on a driver that never knew it. MySQL and PostgreSQL report both.
 */
it('reports null for a length the driver does not store', function (): void {
    $email = app(SchemaInspector::class)->inspect()->table('customers')?->column('email');

    expect($email?->type)->toBe('varchar')
        ->and($email?->length)->toBeNull()
        ->and($email?->unsigned)->toBeNull()
        ->and($email?->comparable())->not->toHaveKey('length');
});

it('reads the same schema twice without querying twice', function (): void {
    $inspector = app(SchemaInspector::class);

    expect($inspector->inspect())->toBe($inspector->inspect());
});

it('round-trips a schema through a snapshot', function (): void {
    $schema = app(SchemaInspector::class)->inspect();

    $decoded = SchemaSnapshot::decode(SchemaSnapshot::encode($schema, '2026-08-10T00:00:00+00:00'), 'test');

    expect($decoded->toArray())->toBe($schema->toArray())
        ->and(app(SchemaDiffer::class)->diff($schema, $decoded)->isEmpty())->toBeTrue();
});

it('refuses a snapshot it cannot understand', function (string $json, string $because): void {
    expect(fn (): Difflock\Schema\DatabaseSchema => SchemaSnapshot::decode($json, '/tmp/schema.json'))
        ->toThrow(Difflock\Exceptions\InvalidSnapshot::class, $because);
})->with([
    ['{not json', 'could not be read'],
    ['{"difflock": 1}', 'no schema in it'],
    ['{"difflock": 99, "schema": {}}', 'newer version'],
]);

it('writes and reads a baseline', function (): void {
    $baseline = app(Baseline::class);

    expect($baseline->exists())->toBeFalse();

    $baseline->write(app(SchemaInspector::class)->inspect());

    expect($baseline->exists())->toBeTrue()
        ->and($baseline->read()->tableNames())->toContain('orders');
});

it('refuses to invent a baseline that was never recorded', function (): void {
    expect(fn () => app(Baseline::class)->read())
        ->toThrow(Difflock\Exceptions\MissingBaseline::class, 'difflock:diff --save');
});

it('reads unsigned only where the driver has the concept', function (): void {
    expect(TypeDefinition::unsigned('int(10) unsigned', 'mysql'))->toBeTrue()
        ->and(TypeDefinition::unsigned('int(10)', 'mariadb'))->toBeFalse()
        ->and(TypeDefinition::unsigned('integer', 'pgsql'))->toBeNull()
        ->and(TypeDefinition::unsigned('integer', 'sqlite'))->toBeNull();
});

it('reads lengths and precisions out of a type, and nothing out of one it cannot parse', function (): void {
    expect(TypeDefinition::length('varchar', 'varchar(255)'))->toBe(255)
        ->and(TypeDefinition::length('decimal', 'decimal(8,2)'))->toBeNull()
        ->and(TypeDefinition::precision('decimal', 'decimal(8,2)'))->toBe(8)
        ->and(TypeDefinition::scale('decimal', 'decimal(8,2)'))->toBe(2)
        ->and(TypeDefinition::scale('numeric', 'numeric(8)'))->toBeNull()
        ->and(TypeDefinition::precision('varchar', 'varchar(255)'))->toBeNull()
        ->and(TypeDefinition::length('enum', "enum('a','b')"))->toBeNull()
        ->and(TypeDefinition::length('bigint', 'bigint'))->toBeNull()
        ->and(TypeDefinition::length('timestamp', 'timestamp(0) without time zone'))->toBe(0);
});
