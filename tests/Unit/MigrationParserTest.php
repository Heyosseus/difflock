<?php

declare(strict_types=1);

use Difflock\Migration\Parser\MigrationParser;
use Difflock\Migration\Parser\Unresolved;

it('reads a create with its columns', function (): void {
    $parsed = parseUp(<<<'PHP'
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email', 320);
            $table->string('name')->nullable();
        });
    PHP);

    expect($parsed->statements)->toHaveCount(1);

    $statement = $parsed->statements[0];

    expect($statement->method)->toBe('create')
        ->and($statement->table)->toBe('users')
        ->and($statement->isCreate())->toBeTrue()
        ->and($statement->operations)->toHaveCount(3);

    expect($statement->operations[1]->method)->toBe('string')
        ->and($statement->operations[1]->arguments)->toBe(['email', 320]);

    expect($statement->operations[2]->hasModifier('nullable'))->toBeTrue();
});

it('reads a chained column definition as an operation with modifiers', function (): void {
    $operation = parseUp(<<<'PHP'
        Schema::table('users', function (Blueprint $table) {
            $table->string('email', 320)->nullable(false)->default('x')->change();
        });
    PHP)->statements[0]->operations[0];

    expect($operation->method)->toBe('string')
        ->and($operation->intArgument(1))->toBe(320)
        ->and($operation->hasModifier('change'))->toBeTrue()
        ->and($operation->modifier('nullable')?->argument(0))->toBeFalse()
        ->and($operation->modifier('default')?->argument(0))->toBe('x');
});

it('reads drops, renames and dropIfExists', function (): void {
    $parsed = parseUp(<<<'PHP'
        Schema::dropIfExists('sessions');
        Schema::drop('cache');
        Schema::rename('posts', 'articles');
    PHP);

    expect(array_map(fn (Difflock\Migration\Parser\SchemaStatement $s): string => $s->method, $parsed->statements))
        ->toBe(['dropIfExists', 'drop', 'rename']);

    expect($parsed->statements[0]->isDrop())->toBeTrue()
        ->and($parsed->statements[2]->operations[0]->method)->toBe('renameTable')
        ->and($parsed->statements[2]->operations[0]->arguments)->toBe(['posts', 'articles']);
});

it('reads array arguments, including ones it cannot resolve', function (): void {
    $operations = parseUp(<<<'PHP'
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['legacy_token', 'legacy_secret']);
            $table->dropColumn(['status', $column]);
            $table->index(['customer_id', 'created_at']);
        });
    PHP)->statements[0]->operations;

    expect($operations[0]->columns())->toBe(['legacy_token', 'legacy_secret'])
        ->and($operations[0]->fullyResolved())->toBeTrue();

    expect($operations[1]->columns())->toBe(['status'])
        ->and($operations[1]->fullyResolved())->toBeFalse();

    expect($operations[2]->columns())->toBe(['customer_id', 'created_at']);
});

it('reads variadic column arguments', function (): void {
    $operation = parseUp(<<<'PHP'
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('a', 'b', 'c');
        });
    PHP)->statements[0]->operations[0];

    expect($operation->allColumns())->toBe(['a', 'b', 'c']);
});

it('marks operations inside a conditional and warns about it', function (): void {
    $parsed = parseUp(<<<'PHP'
        Schema::table('users', function (Blueprint $table) {
            $table->string('always');

            if (config('features.phone')) {
                $table->string('phone');
            }
        });
    PHP);

    $operations = $parsed->statements[0]->operations;

    expect($operations[0]->conditional)->toBeFalse()
        ->and($operations[1]->conditional)->toBeTrue()
        ->and($parsed->warnings)->not->toBeEmpty();
});

it('marks a whole schema statement inside a loop as conditional', function (): void {
    $statement = parseUp(<<<'PHP'
        foreach ($tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('legacy');
            });
        }
    PHP)->statements[0];

    expect($statement->conditional)->toBeTrue()
        ->and($statement->table)->toBeNull()
        ->and($statement->operations[0]->conditional)->toBeTrue();
});

it('marks a braceless conditional statement as conditional', function (): void {
    $statement = parseUp(<<<'PHP'
        if ($enabled) Schema::drop('cache');
    PHP)->statements[0];

    expect($statement->conditional)->toBeTrue();
});

it('warns about raw SQL it cannot read', function (): void {
    $parsed = parseUp(<<<'PHP'
        DB::statement('ALTER TABLE users DROP COLUMN legacy_token');
    PHP);

    expect($parsed->statements)->toBeEmpty()
        ->and($parsed->warnings)->toHaveCount(1)
        ->and($parsed->warnings[0])->toContain('raw DB statement');
});

it('records whether the migration has a down method with a body', function (): void {
    expect(parseUp("Schema::drop('a');")->reversible)->toBeFalse()
        ->and(parseUp("Schema::drop('a');", "Schema::create('a', fn () => null);")->reversible)->toBeTrue();
});

it('warns when there is no up method at all', function (): void {
    $parsed = (new MigrationParser)->parse('<?php class Nothing {}', 'nothing');

    expect($parsed->statements)->toBeEmpty()
        ->and($parsed->warnings[0])->toContain('No up() method');
});

it('reads an arrow function closure', function (): void {
    $statement = parseUp(<<<'PHP'
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('token'));
    PHP)->statements[0];

    expect($statement->operations)->toHaveCount(1)
        ->and($statement->operations[0]->method)->toBe('dropColumn')
        ->and($statement->operations[0]->columns())->toBe(['token']);
});

it('follows a connection call before the schema method', function (): void {
    $statement = parseUp(<<<'PHP'
        Schema::connection('reporting')->dropIfExists('stale_totals');
    PHP)->statements[0];

    expect($statement->connection)->toBe('reporting')
        ->and($statement->table)->toBe('stale_totals');
});

it('recognises the facade however it is written', function (): void {
    $parsed = parseUp(<<<'PHP'
        \Illuminate\Support\Facades\Schema::drop('a');
        Facades\Schema::drop('b');
    PHP);

    expect(array_map(fn (Difflock\Migration\Parser\SchemaStatement $s): ?string => $s->table, $parsed->statements))->toBe(['a', 'b']);
});

it('ignores chains on anything but the blueprint variable', function (): void {
    $statement = parseUp(<<<'PHP'
        Schema::table('users', function (Blueprint $table) {
            $this->helper('dropColumn');
            $table->dropColumn('real');
        });
    PHP)->statements[0];

    expect($statement->operations)->toHaveCount(1)
        ->and($statement->operations[0]->columns())->toBe(['real']);
});

it('resolves scalar literals and leaves expressions unresolved', function (): void {
    $operations = parseUp(<<<'PHP'
        Schema::table('t', function (Blueprint $table) {
            $table->integer('a')->default(-5);
            $table->float('b')->default(1.5);
            $table->boolean('c')->default(true);
            $table->string('d')->default(null);
            $table->string('e')->default($value);
            $table->string('f', 8)->default("line\nbreak");
        });
    PHP)->statements[0]->operations;

    expect($operations[0]->modifier('default')?->argument(0))->toBe(-5)
        ->and($operations[1]->modifier('default')?->argument(0))->toBe(1.5)
        ->and($operations[2]->modifier('default')?->argument(0))->toBeTrue()
        ->and($operations[3]->modifier('default')?->argument(0))->toBeNull()
        ->and($operations[4]->modifier('default')?->argument(0))->toBeInstanceOf(Unresolved::class)
        ->and($operations[5]->modifier('default')?->argument(0))->toBe("line\nbreak");
});

it('reads array() as well as [] and named arguments', function (): void {
    $operations = parseUp(<<<'PHP'
        Schema::table('t', function (Blueprint $table) {
            $table->index(array('a', 'b'));
            $table->string('c')->nullable(value: true);
        });
    PHP)->statements[0]->operations;

    expect($operations[0]->columns())->toBe(['a', 'b'])
        ->and($operations[1]->modifier('nullable')?->argument(0))->toBeTrue();
});

it('normalises Schema::dropColumns into a dropColumn operation', function (): void {
    $statement = parseUp(<<<'PHP'
        Schema::dropColumns('users', ['a', 'b']);
    PHP)->statements[0];

    expect($statement->operations[0]->method)->toBe('dropColumn')
        ->and($statement->operations[0]->columns())->toBe(['a', 'b']);
});

it('lists the tables a migration names, once each', function (): void {
    $parsed = parseUp(<<<'PHP'
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('a'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('b'));
        Schema::drop('orders');
    PHP);

    expect($parsed->tables())->toBe(['users', 'orders']);
});

it('describes an unresolved argument with its source text', function (): void {
    $argument = parseUp(<<<'PHP'
        Schema::table('t', fn (Blueprint $table) => $table->dropColumn($this->legacyColumn()));
    PHP)->statements[0]->operations[0]->argument(0);

    expect($argument)->toBeInstanceOf(Unresolved::class)
        ->and((string) $argument)->toContain('legacyColumn');

    expect((string) new Unresolved)->toBe('<unresolved>');
});
