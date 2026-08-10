<?php

declare(strict_types=1);

use Difflock\Diff\ChangeType;
use Difflock\Diff\SchemaComparator;
use Difflock\Schema\Column;
use Difflock\Schema\DatabaseSchema;
use Difflock\Schema\ForeignKey;
use Difflock\Schema\Index;
use Difflock\Schema\Table;

function comparator(): SchemaComparator
{
    return new SchemaComparator;
}

function schemaWith(Table ...$tables): DatabaseSchema
{
    return new DatabaseSchema(array_values($tables), 'sqlite', 'testing');
}

it('finds nothing between identical schemas', function (): void {
    $schema = schemaWith(new Table('users', [column('id'), column('email')]));

    $diff = comparator()->diff($schema, $schema);

    expect($diff->isEmpty())->toBeTrue()
        ->and($diff->count())->toBe(0);
});

it('finds an added table', function (): void {
    $diff = comparator()->diff(schemaWith(), schemaWith(new Table('users')));

    expect($diff->tables)->toHaveCount(1)
        ->and($diff->tables[0]->type)->toBe(ChangeType::Added)
        ->and($diff->tables[0]->name)->toBe('users')
        ->and($diff->count())->toBe(1);
});

it('finds a removed table', function (): void {
    $diff = comparator()->diff(schemaWith(new Table('users')), schemaWith());

    expect($diff->tables[0]->type)->toBe(ChangeType::Removed);
});

it('counts a dropped table as one change, not one per column', function (): void {
    $diff = comparator()->diff(
        schemaWith(new Table('users', [column('a'), column('b'), column('c')])),
        schemaWith(),
    );

    expect($diff->count())->toBe(1);
});

it('finds added and removed columns', function (): void {
    $diff = comparator()->diff(
        schemaWith(new Table('users', [column('id'), column('legacy_token')])),
        schemaWith(new Table('users', [column('id'), column('phone')])),
    );

    $columns = $diff->tables[0]->columns;

    expect($diff->tables[0]->type)->toBe(ChangeType::Changed)
        ->and($columns)->toHaveCount(2)
        ->and($columns[0]->name)->toBe('legacy_token')
        ->and($columns[0]->type)->toBe(ChangeType::Removed)
        ->and($columns[1]->name)->toBe('phone')
        ->and($columns[1]->type)->toBe(ChangeType::Added);
});

it('finds a changed column and says which fields changed', function (): void {
    $diff = comparator()->diff(
        schemaWith(new Table('users', [column('email', definition: 'varchar(255)', length: 255)])),
        schemaWith(new Table('users', [column('email', definition: 'varchar(320)', length: 320)])),
    );

    $column = $diff->tables[0]->columns[0];

    expect($column->type)->toBe(ChangeType::Changed)
        ->and($column->changes)->toHaveKey('length')
        ->and($column->changes['length'])->toBe(['from' => 255, 'to' => 320]);
});

it('finds a nullability change', function (): void {
    $diff = comparator()->diff(
        schemaWith(new Table('users', [column('email', nullable: true)])),
        schemaWith(new Table('users', [column('email', nullable: false)])),
    );

    expect($diff->tables[0]->columns[0]->changes['nullable'])->toBe(['from' => true, 'to' => false]);
});

it('does not report a difference in a field only one side reported', function (): void {
    $mysql = new Column('id', 'int', 'int(11) unsigned', false, unsigned: true);
    $postgres = new Column('id', 'int', 'int(11) unsigned', false);

    $diff = comparator()->diff(
        schemaWith(new Table('users', [$postgres])),
        schemaWith(new Table('users', [$mysql])),
    );

    expect($diff->isEmpty())->toBeTrue();
});

it('finds added, removed and changed indexes', function (): void {
    $diff = comparator()->diff(
        schemaWith(new Table('users', [], [
            new Index('users_old_index', ['old']),
            new Index('users_email_index', ['email']),
        ])),
        schemaWith(new Table('users', [], [
            new Index('users_phone_index', ['phone']),
            new Index('users_email_index', ['email'], unique: true),
        ])),
    );

    $indexes = $diff->tables[0]->indexes;

    expect($indexes)->toHaveCount(3)
        ->and($indexes[0]->name)->toBe('users_email_index')
        ->and($indexes[0]->type)->toBe(ChangeType::Changed)
        ->and($indexes[0]->changes['unique'])->toBe(['from' => false, 'to' => true])
        ->and($indexes[1]->name)->toBe('users_old_index')
        ->and($indexes[1]->type)->toBe(ChangeType::Removed)
        ->and($indexes[2]->name)->toBe('users_phone_index')
        ->and($indexes[2]->type)->toBe(ChangeType::Added);
});

it('finds added, removed and changed foreign keys', function (): void {
    $diff = comparator()->diff(
        schemaWith(new Table('orders', [], [], [
            new ForeignKey('orders_user_id_foreign', ['user_id'], 'users', ['id'], 'restrict'),
            new ForeignKey('orders_old_foreign', ['old_id'], 'olds', ['id']),
        ])),
        schemaWith(new Table('orders', [], [], [
            new ForeignKey('orders_user_id_foreign', ['user_id'], 'users', ['id'], 'cascade'),
            new ForeignKey('orders_new_foreign', ['new_id'], 'news', ['id']),
        ])),
    );

    $keys = $diff->tables[0]->foreignKeys;

    expect($keys)->toHaveCount(3)
        ->and($keys[0]->name)->toBe('orders_new_foreign')
        ->and($keys[0]->type)->toBe(ChangeType::Added)
        ->and($keys[1]->type)->toBe(ChangeType::Removed)
        ->and($keys[2]->changes['on_delete'])->toBe(['from' => 'restrict', 'to' => 'cascade']);
});

it('is deterministic regardless of the order things were listed in', function (): void {
    $one = new Table('users', [column('b'), column('a')], [new Index('z', ['b']), new Index('a', ['a'])]);
    $two = new Table('users', [column('a'), column('b')], [new Index('a', ['a']), new Index('z', ['b'])]);

    expect(comparator()->diff(schemaWith($one), schemaWith($two))->isEmpty())->toBeTrue()
        ->and($one->toArray())->toBe($two->toArray());
});

it('renders each element the way the console prints it', function (): void {
    expect(column('email', nullable: true, default: "'x'")->render())
        ->toBe("VARCHAR(255) NULL DEFAULT 'x'")
        ->and((new Column('id', 'int', 'int', false, autoIncrement: true))->render())
        ->toBe('INT NOT NULL AUTO_INCREMENT')
        ->and((new Index('pk', ['id'], primary: true))->render())->toBe('PRIMARY (id)')
        ->and((new Index('u', ['email'], unique: true))->render())->toBe('UNIQUE (email)')
        ->and((new Index('i', ['a', 'b']))->render())->toBe('INDEX (a, b)')
        ->and((new ForeignKey('f', ['user_id'], 'users', ['id'], 'cascade', 'restrict'))->render())
        ->toBe('user_id → users(id) ON DELETE CASCADE ON UPDATE RESTRICT');
});

it('serialises a diff to a stable array', function (): void {
    $diff = comparator()->diff(schemaWith(), schemaWith(new Table('users', [column('id')])));

    expect($diff->toArray())->toMatchArray([
        'from' => 'testing',
        'to' => 'testing',
        'changes' => 1,
    ]);
});

it('drops ignored tables from a schema', function (): void {
    $schema = schemaWith(new Table('users'), new Table('telescope_entries'));

    expect($schema->without(['telescope_entries'])->tableNames())->toBe(['users']);
});

it('finds a table by name and an index by its columns', function (): void {
    $table = new Table('users', [column('email')], [new Index('users_email_index', ['email'])]);

    expect($table->hasColumn('email'))->toBeTrue()
        ->and($table->hasColumn('nope'))->toBeFalse()
        ->and($table->indexOn(['email'])?->name)->toBe('users_email_index')
        ->and($table->indexOn(['nope']))->toBeNull()
        ->and($table->foreignKey('nope'))->toBeNull()
        ->and(schemaWith($table)->table('users'))->toBe($table)
        ->and(schemaWith($table)->table('nope'))->toBeNull();
});
