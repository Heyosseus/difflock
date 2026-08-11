<?php

declare(strict_types=1);

use Difflock\Migration\Rules\ChangeColumnRule;
use Difflock\Migration\Rules\DropColumnRule;
use Difflock\Risk\RiskLevel;
use Difflock\Schema\Index;
use Difflock\Schema\SchemaSnapshot;
use Difflock\Schema\Table;

/**
 * A snapshot is read back from a file somebody could have hand-edited, so every
 * field is treated as untrusted. An entry the reader cannot make sense of is
 * skipped, not guessed at: half a column is not a column, and a schema assembled
 * from guesses would produce a diff full of differences that are not there.
 */
it('skips snapshot entries it cannot make sense of', function (): void {
    $schema = SchemaSnapshot::decode(json_encode([
        'difflock' => 1,
        'schema' => [
            'driver' => 'mysql',
            'tables' => [
                'not a table at all',
                ['comment' => 'a table with no name'],
                [
                    'name' => 'users',
                    'columns' => ['not a column', ['type' => 'varchar'], ['name' => 'email']],
                    'indexes' => 'not a list',
                    'foreign_keys' => [
                        ['columns' => ['a']],
                        ['name' => 'fk', 'columns' => 'not a list'],
                        ['name' => 'ok', 'columns' => ['a'], 'foreign_table' => 'other'],
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR), 'test');

    $users = $schema->table('users');

    expect($schema->tableNames())->toBe(['users'])
        ->and($schema->driver)->toBe('mysql')
        ->and(array_keys($users?->columns ?? []))->toBe(['email'])
        ->and($users?->indexes)->toBe([])
        ->and(array_keys($users?->foreignKeys ?? []))->toBe(['ok'])
        ->and($users?->foreignKey('ok')?->columns)->toBe(['a']);
});

it('reads back the optional fields a driver did report', function (): void {
    $schema = SchemaSnapshot::decode(json_encode([
        'difflock' => 1,
        'schema' => [
            'tables' => [[
                'name' => 'users',
                'columns' => [[
                    'name' => 'id',
                    'type' => 'int',
                    'definition' => 'int unsigned',
                    'nullable' => false,
                    'auto_increment' => true,
                    'unsigned' => true,
                    'length' => 10,
                    'precision' => null,
                ]],
                'indexes' => [['name' => 'pk', 'columns' => ['id'], 'primary' => true, 'unique' => true]],
            ]],
        ],
    ], JSON_THROW_ON_ERROR), 'test');

    $id = $schema->table('users')?->column('id');

    expect($id?->unsigned)->toBeTrue()
        ->and($id?->length)->toBe(10)
        ->and($id?->precision)->toBeNull()
        ->and($id?->autoIncrement)->toBeTrue()
        ->and($schema->table('users')?->index('pk')?->primary)->toBeTrue();
});

it('lists every index a dropped column takes with it', function (): void {
    $findings = (new DropColumnRule)->analyze(ruleContext(
        "Schema::table('users', fn (Blueprint \$t) => \$t->dropColumn('email'));",
        [new Table('users', [column('email')], [
            new Index('users_email_index', ['email']),
            new Index('users_email_unique', ['email'], unique: true),
        ])],
        ['users' => 1],
    ));

    expect($findings[0]->context)
        ->toContain('users_email_index and users_email_unique')
        ->toContain('1 row');
});

it('treats a type change against an unknown row count as high, not low', function (): void {
    $findings = (new ChangeColumnRule)->analyze(ruleContext(
        "Schema::table('users', fn (Blueprint \$t) => \$t->integer('reference')->nullable()->change());",
        [new Table('users', [column('reference', nullable: true)])],
    ));

    expect($findings[0]->risk)->toBe(RiskLevel::High);
});

it('treats a length reduction against an empty table as low', function (): void {
    $findings = (new ChangeColumnRule)->analyze(ruleContext(
        "Schema::table('users', fn (Blueprint \$t) => \$t->string('email', 10)->nullable()->change());",
        [new Table('users', [column('email', nullable: true)])],
        ['users' => 0],
    ));

    expect($findings[0]->risk)->toBe(RiskLevel::Low);
});
