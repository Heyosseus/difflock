<?php

declare(strict_types=1);

use Difflock\Contracts\MigrationRule;
use Difflock\Database\DatabaseContextFactory;
use Difflock\Difflock as Manager;
use Difflock\Facades\Difflock;
use Difflock\Migration\IgnoreList;
use Difflock\Migration\MigrationContext;
use Difflock\Migration\MigrationLocator;
use Difflock\Migration\MigrationScope;
use Difflock\Migration\Parser\MigrationParser;
use Difflock\Migration\RuleMigrationAnalyzer;
use Difflock\Risk\RiskLevel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('legacy_token')->nullable();
    });

    config()->set('difflock.migrations.paths', [fixtures('safe')]);
});

it('exposes the whole package through one object', function (): void {
    $difflock = app(Manager::class);

    expect($difflock->inspect()->hasTable('users'))->toBeTrue()
        ->and($difflock->diff()->isEmpty())->toBeTrue()
        ->and($difflock->baseline()->exists())->toBeFalse();

    $recorded = $difflock->record();

    expect($recorded->hasTable('users'))->toBeTrue()
        ->and($difflock->baseline()->exists())->toBeTrue()
        ->and($difflock->drift()->isEmpty())->toBeTrue();

    Schema::table('users', fn (Blueprint $table) => $table->string('phone')->nullable());

    expect(app(Manager::class)->drift()->count())->toBe(1);
});

it('answers the same questions through the facade', function (): void {
    expect(Difflock::inspect()->hasTable('users'))->toBeTrue()
        ->and(Difflock::diff('testing', 'testing')->isEmpty())->toBeTrue()
        ->and(Difflock::lint())->toBeArray()
        ->and(Difflock::analyze(MigrationScope::All)->highestRisk())->toBe(RiskLevel::Safe)
        ->and(Difflock::guard()->allowed())->toBeTrue();
});

it('returns itself from rule() so registrations can be chained', function (): void {
    $rule = new class implements MigrationRule
    {
        public function identifier(): string
        {
            return 'chained';
        }

        public function analyze(MigrationContext $context): array
        {
            return [];
        }
    };

    expect(app(Manager::class)->rule($rule))->toBeInstanceOf(Manager::class);
});

it('says a migration was not analysed rather than reporting it clean', function (): void {
    $files = new class(app(Filesystem::class)) extends Filesystem
    {
        public function __construct() {}

        public function isReadable($path): bool
        {
            return false;
        }
    };

    $analyzer = new RuleMigrationAnalyzer(
        app(MigrationLocator::class),
        app(MigrationParser::class),
        $files,
        app(DatabaseContextFactory::class),
        [],
        new IgnoreList,
    );

    $report = $analyzer->analyze();

    expect($report->migrations)->toHaveCount(1)
        ->and($report->findings)->toBeEmpty()
        ->and($report->warnings())->toContain('The migration file could not be read, so it was not analysed.');
});

it('treats a migration the repository has recorded as no longer pending', function (): void {
    $locator = app(MigrationLocator::class);

    expect($locator->locate(MigrationScope::Pending))->toHaveCount(1);

    runCommand('migrate', ['--path' => [fixtures('safe')], '--realpath' => true, '--force' => true]);

    $after = app(MigrationLocator::class);

    expect($after->locate(MigrationScope::Pending))->toBeEmpty()
        ->and($after->locate(MigrationScope::All))->toHaveCount(1)
        ->and($after->locate(MigrationScope::All)[0]->pending)->toBeFalse();
});

it('treats everything as pending when there is no repository to ask', function (): void {
    expect(app(MigrationLocator::class)->locate(MigrationScope::Pending))->toHaveCount(1);
});

it('names the migration and finds operations by method on a context', function (): void {
    $context = ruleContext(<<<'PHP'
        Schema::table('users', function (Blueprint $table) {
            $table->string('a');
            $table->dropColumn('b');
        });
    PHP);

    expect($context->migrationName())->toBe('2026_08_10_120000_test')
        ->and($context->operations())->toHaveCount(2)
        ->and($context->operations('dropColumn'))->toHaveCount(1)
        ->and($context->operations('nothingAtAll'))->toBeEmpty()
        ->and($context->reversible())->toBeTrue()
        ->and($context->liveTable())->toBeNull()
        ->and($context->rows())->toBeNull();
});
