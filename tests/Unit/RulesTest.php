<?php

declare(strict_types=1);

use Difflock\Contracts\MigrationRule;
use Difflock\Migration\MigrationContext;
use Difflock\Migration\Rules\AddIndexRule;
use Difflock\Migration\Rules\AddNotNullColumnRule;
use Difflock\Migration\Rules\ChangeColumnRule;
use Difflock\Migration\Rules\DropColumnRule;
use Difflock\Migration\Rules\DropIndexRule;
use Difflock\Migration\Rules\DropTableRule;
use Difflock\Migration\Rules\ForeignKeyRule;
use Difflock\Migration\Rules\LargeTableRule;
use Difflock\Migration\Rules\RenameColumnRule;
use Difflock\Migration\Subject;
use Difflock\Risk\RiskLevel;
use Difflock\Schema\ForeignKey;
use Difflock\Schema\Index;
use Difflock\Schema\Table;

/**
 * @return list<Difflock\Migration\MigrationFinding>
 */
function run(MigrationRule $rule, MigrationContext $context): array
{
    return $rule->analyze($context);
}

describe('drop-table', function (): void {
    it('is critical and never reversible', function (): void {
        $findings = run(new DropTableRule, ruleContext("Schema::dropIfExists('users');"));

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->risk)->toBe(RiskLevel::Critical)
            ->and($findings[0]->rule)->toBe('drop-table')
            ->and($findings[0]->destructive)->toBeTrue()
            ->and($findings[0]->reversible)->toBeFalse()
            ->and($findings[0]->subjectType)->toBe(Subject::Table)
            ->and($findings[0]->message)->toBe('DROP TABLE users');
    });

    it('mentions the size when the database can say', function (): void {
        $findings = run(new DropTableRule, ruleContext(
            "Schema::drop('orders');",
            [new Table('orders')],
            ['orders' => 8_421_392],
        ));

        expect($findings[0]->explanation)->toContain('8,421,392 rows');
    });

    it('says when the database could not be reached', function (): void {
        $context = contextFor(parseUp("Schema::drop('orders');"), available: false);

        expect(run(new DropTableRule, $context)[0]->explanation)
            ->toContain('could not be reached');
    });

    it('says when the table is not on the inspected database', function (): void {
        expect(run(new DropTableRule, ruleContext("Schema::drop('orders');"))[0]->explanation)
            ->toContain('does not exist on the inspected database');
    });

    it('reports dropping every table', function (): void {
        $findings = run(new DropTableRule, ruleContext('Schema::dropAllTables();'));

        expect($findings[0]->risk)->toBe(RiskLevel::Critical)
            ->and($findings[0]->message)->toBe('DROP ALL TABLES');
    });

    it('says nothing about a create', function (): void {
        expect(run(new DropTableRule, ruleContext("Schema::create('users', fn (Blueprint \$t) => \$t->id());")))
            ->toBeEmpty();
    });
});

describe('drop-column', function (): void {
    it('is critical, destructive and not reversible', function (): void {
        $findings = run(new DropColumnRule, ruleContext(<<<'PHP'
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('legacy_token');
            });
        PHP));

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->risk)->toBe(RiskLevel::Critical)
            ->and($findings[0]->message)->toBe('DROP COLUMN users.legacy_token')
            ->and($findings[0]->subject)->toBe('legacy_token')
            ->and($findings[0]->destructive)->toBeTrue()
            ->and($findings[0]->reversible)->toBeFalse();
    });

    it('reports one finding per column', function (): void {
        expect(run(new DropColumnRule, ruleContext(<<<'PHP'
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn(['a', 'b']);
            });
        PHP)))->toHaveCount(2);
    });

    it('resolves the columns a shorthand drop implies', function (): void {
        $findings = run(new DropColumnRule, ruleContext(<<<'PHP'
            Schema::table('users', function (Blueprint $table) {
                $table->dropTimestamps();
                $table->dropSoftDeletes();
                $table->dropRememberToken();
            });
        PHP));

        expect(array_map(fn (Difflock\Migration\MigrationFinding $f): ?string => $f->subject, $findings))
            ->toBe(['created_at', 'updated_at', 'deleted_at', 'remember_token']);
    });

    it('names what else in the schema is built on the column', function (): void {
        $findings = run(new DropColumnRule, ruleContext(
            <<<'PHP'
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('customer_id');
            });
            PHP,
            [new Table(
                'orders',
                [column('customer_id')],
                [new Index('orders_customer_id_index', ['customer_id'])],
                [new ForeignKey('orders_customer_id_foreign', ['customer_id'], 'customers', ['id'])],
            )],
            ['orders' => 12],
        ));

        expect($findings[0]->explanation)
            ->toContain('orders_customer_id_index')
            ->toContain('orders_customer_id_foreign')
            ->toContain('12 rows');
    });

    it('says so rather than guessing when the column name is an expression', function (): void {
        $findings = run(new DropColumnRule, ruleContext(<<<'PHP'
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn($this->legacy());
            });
        PHP));

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->risk)->toBe(RiskLevel::Critical)
            ->and($findings[0]->message)->toContain('named by an expression')
            ->and($findings[0]->subject)->toBeNull();
    });

    it('reports both the resolved columns and the unresolved one', function (): void {
        $findings = run(new DropColumnRule, ruleContext(<<<'PHP'
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn(['status', $extra]);
            });
        PHP));

        expect($findings)->toHaveCount(2)
            ->and($findings[0]->subject)->toBe('status')
            ->and($findings[1]->message)->toContain('named by an expression');
    });

    it('mentions the constraint that goes with a constrained foreign id', function (): void {
        $findings = run(new DropColumnRule, ruleContext(<<<'PHP'
            Schema::table('orders', function (Blueprint $table) {
                $table->dropConstrainedForeignId('customer_id');
            });
        PHP));

        expect($findings[0]->explanation)->toContain('foreign key constraint');
    });
});

describe('rename-column', function (): void {
    it('is high because the application breaks, not the data', function (): void {
        $findings = run(new RenameColumnRule, ruleContext(<<<'PHP'
            Schema::table('users', function (Blueprint $table) {
                $table->renameColumn('name', 'full_name');
            });
        PHP));

        expect($findings[0]->risk)->toBe(RiskLevel::High)
            ->and($findings[0]->destructive)->toBeFalse()
            ->and($findings[0]->message)->toBe('RENAME COLUMN users.name → full_name')
            ->and($findings[0]->explanation)->toContain('rolling deploy');
    });

    it('reports a renamed table too', function (): void {
        $findings = run(new RenameColumnRule, ruleContext("Schema::rename('posts', 'articles');"));

        expect($findings[0]->risk)->toBe(RiskLevel::High)
            ->and($findings[0]->message)->toBe('RENAME TABLE posts → articles')
            ->and($findings[0]->subjectType)->toBe(Subject::Table);
    });

    it('says unresolved rather than inventing a name', function (): void {
        $findings = run(new RenameColumnRule, ruleContext(<<<'PHP'
            Schema::table('users', function (Blueprint $table) {
                $table->renameColumn($from, 'full_name');
            });
        PHP));

        expect($findings[0]->message)->toContain('<unresolved>');
    });
});

describe('change-column', function (): void {
    it('is high when a populated column goes from nullable to not null', function (): void {
        $findings = run(new ChangeColumnRule, ruleContext(
            <<<'PHP'
            Schema::table('users', function (Blueprint $table) {
                $table->string('email', 255)->change();
            });
            PHP,
            [new Table('users', [column('email', nullable: true)])],
            ['users' => 1_000],
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::High)
            ->and($findings[0]->explanation)->toContain('nullable to NOT NULL')
            ->and($findings[0]->explanation)->toContain('not empty');
    });

    it('is low when the same change lands on an empty table', function (): void {
        $findings = run(new ChangeColumnRule, ruleContext(
            <<<'PHP'
            Schema::table('users', function (Blueprint $table) {
                $table->string('email', 255)->change();
            });
            PHP,
            [new Table('users', [column('email', nullable: true)])],
            ['users' => 0],
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::Low);
    });

    it('is high when a length is reduced', function (): void {
        $findings = run(new ChangeColumnRule, ruleContext(
            <<<'PHP'
            Schema::table('users', function (Blueprint $table) {
                $table->string('email', 100)->change();
            });
            PHP,
            [new Table('users', [column('email', nullable: true, length: 255)])],
            ['users' => 5],
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::High)
            ->and($findings[0]->explanation)->toContain('reduced from 255 to 100');
    });

    it('is low when a length is increased', function (): void {
        $findings = run(new ChangeColumnRule, ruleContext(
            <<<'PHP'
            Schema::table('users', function (Blueprint $table) {
                $table->string('email', 320)->nullable()->change();
            });
            PHP,
            [new Table('users', [column('email', nullable: true, length: 255)])],
            ['users' => 5],
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::Low)
            ->and($findings[0]->explanation)->toContain('increased from 255 to 320');
    });

    it('reports a type family change', function (): void {
        $findings = run(new ChangeColumnRule, ruleContext(
            <<<'PHP'
            Schema::table('users', function (Blueprint $table) {
                $table->integer('reference')->nullable()->change();
            });
            PHP,
            [new Table('users', [column('reference', 'varchar', 'varchar(50)', nullable: true, length: 50)])],
            ['users' => 5],
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::High)
            ->and($findings[0]->explanation)->toContain('TEXT to INTEGER');
    });

    it('reports a dropped default', function (): void {
        $findings = run(new ChangeColumnRule, ruleContext(
            <<<'PHP'
            Schema::table('users', function (Blueprint $table) {
                $table->string('status')->change();
            });
            PHP,
            [new Table('users', [column('status', default: "'draft'")])],
            ['users' => 5],
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::Medium)
            ->and($findings[0]->explanation)->toContain('default is dropped');
    });

    it('is medium and says why when the live column cannot be read', function (): void {
        $findings = run(new ChangeColumnRule, ruleContext(<<<'PHP'
            Schema::table('users', function (Blueprint $table) {
                $table->string('email')->change();
            });
        PHP));

        expect($findings[0]->risk)->toBe(RiskLevel::Medium)
            ->and($findings[0]->explanation)->toContain('could not be read from the database');
    });

    it('is low when nothing it compares actually differs', function (): void {
        $findings = run(new ChangeColumnRule, ruleContext(
            <<<'PHP'
            Schema::table('users', function (Blueprint $table) {
                $table->string('email', 255)->change();
            });
            PHP,
            [new Table('users', [column('email')])],
            ['users' => 5],
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::Low)
            ->and($findings[0]->explanation)->toContain('differs from the column as it exists now');
    });

    it('reports loosening a column to nullable as low', function (): void {
        $findings = run(new ChangeColumnRule, ruleContext(
            <<<'PHP'
            Schema::table('users', function (Blueprint $table) {
                $table->string('email', 255)->nullable()->change();
            });
            PHP,
            [new Table('users', [column('email')])],
            ['users' => 5],
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::Low)
            ->and($findings[0]->explanation)->toContain('NOT NULL to nullable');
    });

    it('never claims to know whether the engine locks', function (): void {
        $findings = run(new ChangeColumnRule, ruleContext(<<<'PHP'
            Schema::table('users', function (Blueprint $table) {
                $table->string('email')->change();
            });
        PHP));

        expect($findings[0]->explanation)->toContain('depends on the database engine');
    });
});

describe('add-not-null-column', function (): void {
    it('is high on a populated table', function (): void {
        $findings = run(new AddNotNullColumnRule, ruleContext(
            <<<'PHP'
            Schema::table('users', function (Blueprint $table) {
                $table->string('status');
            });
            PHP,
            [new Table('users')],
            ['users' => 4_000],
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::High)
            ->and($findings[0]->message)->toBe('ADD NOT NULL COLUMN users.status with no default')
            ->and($findings[0]->explanation)->toContain('4,000 rows');
    });

    it('is low on an empty table', function (): void {
        $findings = run(new AddNotNullColumnRule, ruleContext(
            "Schema::table('users', fn (Blueprint \$t) => \$t->string('status'));",
            [new Table('users')],
            ['users' => 0],
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::Low);
    });

    it('is medium when the row count is unknown', function (): void {
        $findings = run(new AddNotNullColumnRule, ruleContext(
            "Schema::table('users', fn (Blueprint \$t) => \$t->string('status'));",
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::Medium)
            ->and($findings[0]->explanation)->toContain('could not be determined');
    });

    it('says nothing about a nullable column, a defaulted one, or a key', function (): void {
        expect(run(new AddNotNullColumnRule, ruleContext(
            <<<'PHP'
            Schema::table('users', function (Blueprint $table) {
                $table->string('a')->nullable();
                $table->string('b')->default('x');
                $table->timestamp('c')->useCurrent();
                $table->softDeletes();
                $table->timestamps();
                $table->rememberToken();
                $table->id();
            });
            PHP,
            [new Table('users')],
            ['users' => 4_000],
        )))->toBeEmpty();
    });

    it('says nothing inside a create', function (): void {
        expect(run(new AddNotNullColumnRule, ruleContext(
            "Schema::create('users', fn (Blueprint \$t) => \$t->string('status'));",
        )))->toBeEmpty();
    });

    it('leaves an altered column to the change rule', function (): void {
        expect(run(new AddNotNullColumnRule, ruleContext(
            "Schema::table('users', fn (Blueprint \$t) => \$t->string('status')->change());",
        )))->toBeEmpty();
    });
});

describe('add-index', function (): void {
    it('rises with the size of the table', function (int $rows, RiskLevel $expected): void {
        $findings = run(new AddIndexRule, ruleContext(
            "Schema::table('orders', fn (Blueprint \$t) => \$t->index('customer_id'));",
            [new Table('orders')],
            ['orders' => $rows],
        ));

        expect($findings[0]->risk)->toBe($expected);
    })->with([
        [10, RiskLevel::Low],
        [200_000, RiskLevel::Medium],
        [4_921_000, RiskLevel::High],
    ]);

    it('is high for a unique index on a table that might hold duplicates', function (): void {
        $findings = run(new AddIndexRule, ruleContext(
            "Schema::table('users', fn (Blueprint \$t) => \$t->unique('email'));",
            [new Table('users')],
            ['users' => 10],
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::High)
            ->and($findings[0]->explanation)->toContain('duplicates');
    });

    it('is low for a unique index on an empty table', function (): void {
        $findings = run(new AddIndexRule, ruleContext(
            "Schema::table('users', fn (Blueprint \$t) => \$t->unique('email'));",
            [new Table('users')],
            ['users' => 0],
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::Low);
    });

    it('says the size is unknown rather than assuming it is small', function (): void {
        $findings = run(new AddIndexRule, ruleContext(
            "Schema::table('orders', fn (Blueprint \$t) => \$t->index('customer_id'));",
        ));

        expect($findings[0]->explanation)->toContain('could not be determined')
            ->and($findings[0]->explanation)->toContain('depends on the database engine');
    });

    it('says nothing about indexes declared in a create', function (): void {
        expect(run(new AddIndexRule, ruleContext(
            "Schema::create('orders', fn (Blueprint \$t) => \$t->index('customer_id'));",
        )))->toBeEmpty();
    });
});

describe('drop-index', function (): void {
    it('is high for a constraint and medium for a plain index', function (): void {
        $findings = run(new DropIndexRule, ruleContext(
            <<<'PHP'
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique('users_email_unique');
                $table->dropPrimary();
                $table->dropIndex('users_name_index');
            });
            PHP,
            [new Table('users')],
            ['users' => 500_000],
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::High)
            ->and($findings[0]->explanation)->toContain('removes a constraint')
            ->and($findings[1]->risk)->toBe(RiskLevel::High)
            ->and($findings[2]->risk)->toBe(RiskLevel::Medium)
            ->and($findings[2]->explanation)->toContain('no view of your query workload');
    });

    it('is low on a small table', function (): void {
        $findings = run(new DropIndexRule, ruleContext(
            "Schema::table('users', fn (Blueprint \$t) => \$t->dropIndex('users_name_index'));",
            [new Table('users')],
            ['users' => 10],
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::Low);
    });

    it('says what the index covered when the schema can say', function (): void {
        $findings = run(new DropIndexRule, ruleContext(
            "Schema::table('users', fn (Blueprint \$t) => \$t->dropIndex('users_name_index'));",
            [new Table('users', [], [new Index('users_name_index', ['first', 'last'])])],
            ['users' => 10],
        ));

        expect($findings[0]->explanation)->toContain('covers (first, last)');
    });
});

describe('foreign-key', function (): void {
    it('is high for a cascading delete, however it is spelled', function (string $body): void {
        $findings = run(new ForeignKeyRule, ruleContext($body, [new Table('orders')], ['orders' => 10]));

        expect($findings[0]->risk)->toBe(RiskLevel::High)
            ->and($findings[0]->destructive)->toBeTrue()
            ->and($findings[0]->explanation)->toContain('without the application being involved');
    })->with([
        "Schema::table('orders', fn (Blueprint \$t) => \$t->foreignId('customer_id')->constrained()->cascadeOnDelete());",
        "Schema::table('orders', fn (Blueprint \$t) => \$t->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade'));",
    ]);

    it('is high for dropping a constraint', function (): void {
        $findings = run(new ForeignKeyRule, ruleContext(
            "Schema::table('orders', fn (Blueprint \$t) => \$t->dropForeign('orders_customer_id_foreign'));",
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::High)
            ->and($findings[0]->subjectType)->toBe(Subject::Constraint)
            ->and($findings[0]->explanation)->toContain('Referential integrity');
    });

    it('is medium for adding a constraint to a populated table', function (): void {
        $findings = run(new ForeignKeyRule, ruleContext(
            "Schema::table('orders', fn (Blueprint \$t) => \$t->foreignId('customer_id')->constrained());",
            [new Table('orders')],
            ['orders' => 900],
        ));

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->risk)->toBe(RiskLevel::Medium)
            ->and($findings[0]->explanation)->toContain('900 rows');
    });

    it('is low for adding a constraint to an empty table', function (): void {
        $findings = run(new ForeignKeyRule, ruleContext(
            "Schema::table('orders', fn (Blueprint \$t) => \$t->foreignId('customer_id')->constrained());",
            [new Table('orders')],
            ['orders' => 0],
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::Low);
    });

    it('says nothing about a constraint declared in a create, unless it cascades', function (): void {
        expect(run(new ForeignKeyRule, ruleContext(
            "Schema::create('orders', fn (Blueprint \$t) => \$t->foreignId('customer_id')->constrained());",
        )))->toBeEmpty();

        expect(run(new ForeignKeyRule, ruleContext(
            "Schema::create('orders', fn (Blueprint \$t) => \$t->foreignId('customer_id')->constrained()->cascadeOnDelete());",
        )))->toHaveCount(1);
    });

    it('ignores a non-cascading referential action', function (): void {
        expect(run(new ForeignKeyRule, ruleContext(
            "Schema::create('orders', fn (Blueprint \$t) => \$t->foreignId('customer_id')->constrained()->onDelete('set null'));",
        )))->toBeEmpty();
    });
});

describe('large-table', function (): void {
    it('fires only when the size is known and large', function (): void {
        expect(run(new LargeTableRule, ruleContext(
            "Schema::table('orders', fn (Blueprint \$t) => \$t->index('customer_id'));",
            [new Table('orders')],
            ['orders' => 8_421_392],
        )))->toHaveCount(1);

        expect(run(new LargeTableRule, ruleContext(
            "Schema::table('orders', fn (Blueprint \$t) => \$t->index('customer_id'));",
            [new Table('orders')],
            ['orders' => 10],
        )))->toBeEmpty();

        expect(run(new LargeTableRule, ruleContext(
            "Schema::table('orders', fn (Blueprint \$t) => \$t->index('customer_id'));",
        )))->toBeEmpty();
    });

    it('is medium and names the size', function (): void {
        $findings = run(new LargeTableRule, ruleContext(
            "Schema::table('orders', fn (Blueprint \$t) => \$t->index('customer_id'));",
            [new Table('orders')],
            ['orders' => 8_421_392],
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::Medium)
            ->and($findings[0]->message)->toContain('8,421,392 rows')
            ->and($findings[0]->explanation)->toContain('depends on the');
    });

    it('says nothing about a create or an empty statement', function (): void {
        expect(run(new LargeTableRule, ruleContext("Schema::drop('orders');", [new Table('orders')], ['orders' => 8_421_392])))
            ->toBeEmpty();
    });
});

it('gives every rule a kebab-cased identifier', function (MigrationRule $rule): void {
    expect($rule->identifier())->toMatch('/^[a-z][a-z0-9-]*$/');
})->with([
    fn (): DropTableRule => new DropTableRule,
    fn (): DropColumnRule => new DropColumnRule,
    fn (): RenameColumnRule => new RenameColumnRule,
    fn (): ChangeColumnRule => new ChangeColumnRule,
    fn (): AddNotNullColumnRule => new AddNotNullColumnRule,
    fn (): AddIndexRule => new AddIndexRule,
    fn (): DropIndexRule => new DropIndexRule,
    fn (): ForeignKeyRule => new ForeignKeyRule,
    fn (): LargeTableRule => new LargeTableRule,
]);
