<?php

declare(strict_types=1);

use Difflock\Migration\Rules\DropTableRule;
use Difflock\Migration\Rules\SensitiveColumnRule;
use Difflock\Migration\Rules\UnindexedForeignKeyRule;
use Difflock\Migration\Subject;
use Difflock\Risk\RiskLevel;
use Difflock\Schema\Index;
use Difflock\Schema\Table;

describe('sensitive-column', function (): void {
    it('flags payment data at high', function (string $column): void {
        $findings = (new SensitiveColumnRule)->analyze(ruleContext(
            "Schema::create('orders', fn (Blueprint \$t) => \$t->string('{$column}'));",
        ));

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->risk)->toBe(RiskLevel::High)
            ->and($findings[0]->subjectType)->toBe(Subject::Column)
            ->and($findings[0]->subject)->toBe($column);
    })->with(['cvv', 'card_number', 'iban', 'account_number']);

    it('flags identity and credential columns at medium', function (string $column): void {
        $findings = (new SensitiveColumnRule)->analyze(ruleContext(
            "Schema::create('people', fn (Blueprint \$t) => \$t->string('{$column}'));",
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::Medium);
    })->with(['ssn', 'passport_number', 'date_of_birth', 'api_key', 'refresh_token', 'private_key']);

    /**
     * The framework's own starter migration must stay silent, or the rule teaches
     * every new user that security findings are noise.
     */
    it('says nothing about Laravel\'s own users table', function (): void {
        expect((new SensitiveColumnRule)->analyze(ruleContext(<<<'PHP'
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        PHP)))->toBeEmpty();
    });

    it('matches whole words only', function (): void {
        expect((new SensitiveColumnRule)->analyze(ruleContext(<<<'PHP'
            Schema::create('t', function (Blueprint $table) {
                $table->integer('discarded_count');
                $table->string('scvvs');
                $table->string('billing_card_number');
            });
        PHP)))->toHaveCount(1);
    });

    it('says it read only the name and claims nothing more', function (): void {
        $finding = (new SensitiveColumnRule)->analyze(ruleContext(
            "Schema::create('t', fn (Blueprint \$c) => \$c->string('api_key'));",
        ))[0];

        expect($finding->explanation)->toContain('reads the column name only')
            ->and($finding->explanation)->toContain('cannot tell whether the value is')
            ->and($finding->suggestion)->toContain('secrets manager');
    });

    it('leaves an altered column to the change rule', function (): void {
        expect((new SensitiveColumnRule)->analyze(ruleContext(
            "Schema::table('t', fn (Blueprint \$c) => \$c->string('api_key')->change());",
        )))->toBeEmpty();
    });
});

describe('unindexed-foreign-key', function (): void {
    $constrained = "Schema::table('orders', fn (Blueprint \$t) => \$t->foreignId('customer_id')->constrained());";

    it('says nothing on MySQL, which creates the index itself', function (string $driver) use ($constrained): void {
        expect((new UnindexedForeignKeyRule)->analyze(
            driverContext($constrained, $driver, [new Table('orders')], ['orders' => 10]),
        ))->toBeEmpty();
    })->with(['mysql', 'mariadb']);

    it('flags an unindexed foreign key on PostgreSQL', function () use ($constrained): void {
        $findings = (new UnindexedForeignKeyRule)->analyze(
            driverContext($constrained, 'pgsql', [new Table('orders')], ['orders' => 10]),
        );

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->risk)->toBe(RiskLevel::Medium)
            ->and($findings[0]->message)->toBe('UNINDEXED FOREIGN KEY orders.customer_id')
            ->and($findings[0]->explanation)->toContain('PostgreSQL creates no index')
            ->and($findings[0]->suggestion)->toContain("index('customer_id')");
    });

    it('names the engine it is actually talking about', function () use ($constrained): void {
        expect((new UnindexedForeignKeyRule)->analyze(
            driverContext($constrained, 'sqlite', [new Table('orders')], ['orders' => 10]),
        )[0]->explanation)->toContain('SQLite creates no index');
    });

    it('rises to high once the table is big enough to feel it', function () use ($constrained): void {
        expect((new UnindexedForeignKeyRule)->analyze(
            driverContext($constrained, 'pgsql', [new Table('orders')], ['orders' => 500_000]),
        )[0]->risk)->toBe(RiskLevel::High);
    });

    it('is satisfied by an index declared in the same closure', function (): void {
        expect((new UnindexedForeignKeyRule)->analyze(driverContext(<<<'PHP'
            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('customer_id')->constrained();
                $table->index('customer_id');
            });
        PHP, 'pgsql', [new Table('orders')], ['orders' => 10])))->toBeEmpty();
    });

    it('is satisfied only by an index that leads on the column', function () use ($constrained): void {
        $covered = new Table('orders', [], [new Index('i', ['customer_id', 'created_at'])]);
        $useless = new Table('orders', [], [new Index('i', ['created_at', 'customer_id'])]);

        expect((new UnindexedForeignKeyRule)->analyze(
            driverContext($constrained, 'pgsql', [$covered], ['orders' => 10]),
        ))->toBeEmpty();

        expect((new UnindexedForeignKeyRule)->analyze(
            driverContext($constrained, 'pgsql', [$useless], ['orders' => 10]),
        ))->toHaveCount(1);
    });

    it('hedges when the engine is unknown', function () use ($constrained): void {
        $findings = (new UnindexedForeignKeyRule)->analyze(driverContext($constrained, null));

        expect($findings[0]->risk)->toBe(RiskLevel::Low)
            ->and($findings[0]->explanation)->toContain('could not be determined')
            ->and($findings[0]->explanation)->toContain('MySQL and MariaDB');
    });
});

describe('audit tables', function (): void {
    it('says a dropped audit trail is more than lost working data', function (string $table): void {
        $findings = (new DropTableRule)->analyze(ruleContext("Schema::dropIfExists('{$table}');"));

        expect($findings[0]->explanation)->toContain('audit trail')
            ->and($findings[0]->risk)->toBe(RiskLevel::Critical);
    })->with(['activity_log', 'audits', 'payment_history', 'request_logs', 'change_journal']);

    it('does not say it about an ordinary table', function (): void {
        expect((new DropTableRule)->analyze(ruleContext("Schema::dropIfExists('orders');"))[0]->explanation)
            ->not->toContain('audit trail');
    });
});
