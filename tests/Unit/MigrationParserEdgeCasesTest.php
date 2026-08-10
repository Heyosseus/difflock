<?php

declare(strict_types=1);

use Difflock\Migration\Parser\MigrationParser;
use Difflock\Migration\Parser\Unresolved;

/**
 * Everything the parser is asked to read that is not the shape it was built for.
 *
 * The whole point of these is the direction of failure. Every one of them must come
 * back as "I did not understand this" — an empty statement list, or an
 * {@see Unresolved} argument, or a warning — and never as a confident finding about
 * something that is not there. A linter that invents a DROP COLUMN is worse than no
 * linter, because people act on it.
 */
it('reads nothing out of a source it does not understand', function (string $body): void {
    expect(parseUp($body)->statements)->toBeEmpty();
})->with([
    'no method after the facade' => 'Schema::$method("users");',
    'no parentheses' => 'Schema::table;',
    'a chain that goes nowhere' => "Schema::connection('x');",
    'a facade method that reads rather than changes' => "Schema::hasTable('users');",
    'a chain onto a read' => "Schema::hasTable('users')->somethingElse();",
]);

it('warns rather than guessing when a schema closure is not a closure', function (): void {
    $parsed = parseUp("Schema::table('users', \$this->columns());");

    expect($parsed->statements[0]->operations)->toBeEmpty()
        ->and($parsed->warnings)->toContain(
            'A schema closure was written in a form Difflock could not read, '
                .'so the operations inside it were not analysed.',
        );
});

it('warns when a table is named by an expression', function (): void {
    $parsed = parseUp('Schema::table($name, fn (Blueprint $table) => $table->dropColumn("a"));');

    expect($parsed->statements[0]->table)->toBeNull()
        ->and($parsed->warnings[0])->toContain('names its table with an expression');
});

it('reads nothing out of a closure with no body', function (): void {
    expect(parseUp("Schema::table('users', function (Blueprint \$table) {});")->statements[0]->operations)
        ->toBeEmpty();
});

it('reads a create with no closure at all', function (): void {
    expect(parseUp("Schema::create('users');")->statements[0]->operations)->toBeEmpty();
});

it('leaves a property access alone', function (): void {
    expect(parseUp(<<<'PHP'
        Schema::table('users', function (Blueprint $table) {
            $table->name;
        });
    PHP)->statements[0]->operations)->toBeEmpty();
});

it('stops reading a chain at the first thing that is not a call', function (): void {
    $operation = parseUp(<<<'PHP'
        Schema::table('users', function (Blueprint $table) {
            $table->string('a')->nullable()->$dynamic();
        });
    PHP)->statements[0]->operations[0];

    expect($operation->method)->toBe('string')
        ->and($operation->modifiers)->toHaveCount(1);
});

it('leaves a heredoc unresolved rather than reading half of it', function (): void {
    $argument = parseUp(<<<'PHP'
        Schema::table('users', function (Blueprint $table) {
            $table->string('a')->default(<<<'TEXT'
            value
            TEXT);
        });
    PHP)->statements[0]->operations[0]->modifier('default')?->argument(0);

    expect($argument)->toBeInstanceOf(Unresolved::class);
});

it('leaves a nested array unresolved rather than flattening it', function (): void {
    $argument = parseUp(<<<'PHP'
        Schema::table('users', fn (Blueprint $table) => $table->index([['a'], 'b']));
    PHP)->statements[0]->operations[0]->argument(0);

    expect($argument)->toBeArray()
        ->and($argument[0])->toBeArray()
        ->and($argument[1])->toBe('b');
});

it('leaves a concatenation unresolved', function (): void {
    $argument = parseUp(<<<'PHP'
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('legacy_'.$suffix));
    PHP)->statements[0]->operations[0]->argument(0);

    expect($argument)->toBeInstanceOf(Unresolved::class);
});

it('reads a static closure', function (): void {
    expect(parseUp(<<<'PHP'
        Schema::table('users', static function (Blueprint $table) {
            $table->dropColumn('a');
        });
    PHP)->statements[0]->operations)->toHaveCount(1);
});

it('reads an up method with no body as no method at all', function (): void {
    $parsed = (new MigrationParser)->parse(
        '<?php abstract class M { abstract public function up(): void; }',
        'abstract',
    );

    expect($parsed->statements)->toBeEmpty()
        ->and($parsed->warnings[0])->toContain('No up() method');
});

it('is not confused by a method called up on something else', function (): void {
    $parsed = (new MigrationParser)->parse(
        '<?php class M { public function upstream(): void {} public function up(): void { Schema::drop("a"); } }',
        'm',
    );

    expect($parsed->statements)->toHaveCount(1)
        ->and($parsed->statements[0]->table)->toBe('a');
});

it('reads a function up used as a property name without falling over', function (): void {
    expect((new MigrationParser)->parse('<?php function up { }', 'broken')->statements)->toBeEmpty();
});
