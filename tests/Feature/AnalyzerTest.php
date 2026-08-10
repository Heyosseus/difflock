<?php

declare(strict_types=1);

use Difflock\Contracts\MigrationAnalyzer;
use Difflock\Contracts\MigrationRule;
use Difflock\Database\DatabaseContextFactory;
use Difflock\Facades\Difflock;
use Difflock\Migration\MigrationContext;
use Difflock\Migration\MigrationScope;
use Difflock\Migration\Subject;
use Difflock\Risk\RiskLevel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('legacy_token')->nullable();
    });

    Schema::create('orders', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('customer_id');
    });

    config()->set('difflock.migrations.paths', [fixtures()]);
});

it('analyses every pending migration and sorts the worst first', function (): void {
    $report = app(MigrationAnalyzer::class)->analyze();

    expect($report->migrations)->toHaveCount(3)
        ->and($report->findings)->not->toBeEmpty()
        ->and($report->findings[0]->risk)->toBe(RiskLevel::Critical)
        ->and($report->findings[0]->rule)->toBe('drop-column')
        ->and($report->findings[0]->table)->toBe('users')
        ->and($report->findings[0]->subject)->toBe('legacy_token')
        ->and($report->highestRisk())->toBe(RiskLevel::Critical)
        ->and($report->databaseAvailable)->toBeTrue();
});

it('fails only at or above the threshold', function (): void {
    $report = app(MigrationAnalyzer::class)->analyze();

    expect($report->fails(RiskLevel::Critical))->toBeTrue()
        ->and($report->fails(RiskLevel::Low))->toBeTrue();
});

it('finds nothing to complain about in a migration that only creates a table', function (): void {
    $report = app(MigrationAnalyzer::class)->analyze(MigrationScope::Pending, [fixtures('safe')]);

    expect($report->migrations)->toHaveCount(1)
        ->and($report->findings)->toBeEmpty()
        ->and($report->fails(RiskLevel::Safe))->toBeFalse();
});

it('groups findings by the migration they came from', function (): void {
    $report = app(MigrationAnalyzer::class)->analyze();

    expect($report->findingsFor('2026_08_10_120000_remove_legacy_token'))->toHaveCount(1)
        ->and($report->findingsFor('nothing_of_the_sort'))->toBeEmpty();
});

it('drops findings the ignore list covers', function (): void {
    config()->set('difflock.ignore.rules', ['drop-*']);

    expect(app(MigrationAnalyzer::class)->analyze()->findings)
        ->each->not->toHaveProperty('rule', 'drop-column');

    config()->set('difflock.ignore.rules', []);
    config()->set('difflock.ignore.tables', ['users']);

    expect(collect(app(MigrationAnalyzer::class)->analyze()->findings)->pluck('table')->unique()->all())
        ->not->toContain('users');

    config()->set('difflock.ignore.tables', []);
    config()->set('difflock.ignore.migrations', ['2026_08_10_1*']);

    expect(app(MigrationAnalyzer::class)->analyze()->findings)->toBeEmpty();
});

it('serialises a report to a stable document', function (): void {
    $document = app(MigrationAnalyzer::class)->analyze()->toArray();

    expect($document)->toHaveKeys([
        'migrations', 'analyzed', 'risk', 'counts', 'database_available', 'warnings', 'findings',
    ])->and($document['risk'])->toBe('critical')
        ->and($document['analyzed'])->toBe(3);
});

it('runs a rule the application registered at runtime', function (): void {
    Difflock::rule(new class implements MigrationRule
    {
        public function identifier(): string
        {
            return 'no-orders';
        }

        public function analyze(MigrationContext $context): array
        {
            return $context->tableName() === 'orders' ? [$context->finding(
                rule: $this->identifier(),
                risk: RiskLevel::Critical,
                message: 'Nobody touches orders',
                explanation: 'Company policy.',
                subject: 'orders',
                subjectType: Subject::Table,
            )] : [];
        }
    });

    $rules = array_map(
        fn (\Difflock\Migration\MigrationFinding $finding): string => $finding->rule,
        app(MigrationAnalyzer::class)->analyze()->findings,
    );

    expect($rules)->toContain('no-orders');
});

it('lets a registered rule replace a built-in of the same identifier', function (): void {
    Difflock::rule(new class implements MigrationRule
    {
        public function identifier(): string
        {
            return 'drop-column';
        }

        public function analyze(MigrationContext $context): array
        {
            return [];
        }
    });

    expect(app(MigrationAnalyzer::class)->analyze()->findings)
        ->each->not->toHaveProperty('rule', 'drop-column');
});

it('skips a configured rule that is not a rule at all', function (): void {
    config()->set('difflock.rules', [stdClass::class, 'NotAClassAtAll']);

    expect(app(MigrationAnalyzer::class)->analyze()->findings)->toBeEmpty();
});

it('reads the schema once per run, however many migrations there are', function (): void {
    $factory = app(DatabaseContextFactory::class);

    expect($factory->make())->toBe($factory->make());
});

/**
 * The memo must not outlive the run. A shared context would answer the second
 * command with the schema from the first, and a drift check built on that reports
 * no drift — which is the one failure mode this package cannot afford.
 */
it('does not carry a schema reading over into the next run', function (): void {
    $before = app(DatabaseContextFactory::class)->make();

    Schema::create('receipts', fn (Blueprint $table) => $table->id());

    $after = app(DatabaseContextFactory::class)->make();

    expect($before->schema->hasTable('receipts'))->toBeFalse()
        ->and($after->schema->hasTable('receipts'))->toBeTrue();
});

it('exposes the same analysis through the facade', function (): void {
    expect(Difflock::lint())->toEqual(Difflock::analyze()->findings)
        ->and(Difflock::analyze(MigrationScope::All)->migrations)->not->toBeEmpty();
});
