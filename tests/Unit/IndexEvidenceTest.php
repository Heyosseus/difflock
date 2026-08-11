<?php

declare(strict_types=1);

use Difflock\Database\FixedIndexStatistics;
use Difflock\Database\FixedTableStatistics;
use Difflock\Migration\DatabaseContext;
use Difflock\Migration\MigrationContext;
use Difflock\Migration\Rules\DropIndexRule;
use Difflock\Migration\Rules\RedundantIndexRule;
use Difflock\Risk\RiskLevel;
use Difflock\Schema\DatabaseSchema;
use Difflock\Schema\Index;
use Difflock\Schema\Table;

/**
 * @param  list<Table>  $tables
 * @param  array<string, int>  $scans
 */
function usageContext(string $body, array $tables, array $scans = [], ?int $days = null, array $rows = []): MigrationContext
{
    $parsed = parseUp($body, 'x');

    return new MigrationContext(
        $parsed,
        $parsed->statements[0],
        new DatabaseContext(
            schema: new DatabaseSchema($tables, 'pgsql', 'main'),
            statistics: new FixedTableStatistics($rows),
            indexes: new FixedIndexStatistics($scans, $days),
        ),
    );
}

describe('drop-index with usage evidence', function (): void {
    $drop = "Schema::table('orders', fn (Blueprint \$t) => \$t->dropIndex('orders_status_index'));";
    $table = fn (): Table => new Table('orders', [], [new Index('orders_status_index', ['status'])]);

    it('drops to low when the engine says nothing has ever read it', function () use ($drop, $table): void {
        $findings = (new DropIndexRule)->analyze(usageContext(
            $drop, [$table()], ['orders.orders_status_index' => 0], days: 274,
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::Low)
            ->and($findings[0]->explanation)->toContain('no reads of this index')
            ->and($findings[0]->context)->toContain('274 days')
            ->and($findings[0]->suggestion)->toContain('safe to drop');
    });

    it('rises to high when something is reading it', function () use ($drop, $table): void {
        $findings = (new DropIndexRule)->analyze(usageContext(
            $drop, [$table()], ['orders.orders_status_index' => 2_100_000], days: 30,
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::High)
            ->and($findings[0]->context)->toContain('2,100,000 reads')
            ->and($findings[0]->suggestion)->toContain('Find what reads it');
    });

    /**
     * The caveats are the reason the evidence can be trusted. A count without its
     * window is uninterpretable, and a count from one instance says nothing about a
     * replica.
     */
    it('quotes the window and the caveats alongside the number', function () use ($drop, $table): void {
        $findings = (new DropIndexRule)->analyze(usageContext(
            $drop, [$table()], ['orders.orders_status_index' => 0], days: 274,
        ));

        expect($findings[0]->explanation)->toContain('per instance')
            ->toContain('replica')
            ->toContain('short window since a restart proves nothing');
    });

    it('falls back to saying it does not know when the engine will not answer', function () use ($drop, $table): void {
        $findings = (new DropIndexRule)->analyze(usageContext($drop, [$table()]));

        expect($findings[0]->explanation)->toContain('would not say how often')
            ->and($findings[0]->risk)->toBe(RiskLevel::Medium);
    });

    /**
     * Usage evidence must never soften a constraint removal: dropping a unique index
     * is a correctness change whatever the read counters say.
     */
    it('never lets read counts soften a dropped constraint', function (): void {
        $findings = (new DropIndexRule)->analyze(usageContext(
            "Schema::table('users', fn (Blueprint \$t) => \$t->dropUnique('users_email_unique'));",
            [new Table('users', [], [new Index('users_email_unique', ['email'], unique: true)])],
            ['users.users_email_unique' => 0],
            days: 900,
        ));

        expect($findings[0]->risk)->toBe(RiskLevel::High)
            ->and($findings[0]->explanation)->toContain('removes a constraint');
    });
});

describe('redundant-index', function (): void {
    it('spots an index a longer one already covers', function (): void {
        $findings = (new RedundantIndexRule)->analyze(usageContext(
            "Schema::table('orders', fn (Blueprint \$t) => \$t->index('status'));",
            [new Table('orders', [], [new Index('orders_status_type_index', ['status', 'type'])])],
        ));

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->risk)->toBe(RiskLevel::Low)
            ->and($findings[0]->message)->toBe('REDUNDANT INDEX orders (status)')
            ->and($findings[0]->explanation)->toContain('orders_status_type_index')
            ->and($findings[0]->explanation)->toContain('leading subset');
    });

    it('says nothing when the existing index does not lead with the same columns', function (): void {
        expect((new RedundantIndexRule)->analyze(usageContext(
            "Schema::table('orders', fn (Blueprint \$t) => \$t->index('status'));",
            [new Table('orders', [], [new Index('i', ['type', 'status'])])],
        )))->toBeEmpty();
    });

    it('says nothing about an identical index, which is a different problem', function (): void {
        expect((new RedundantIndexRule)->analyze(usageContext(
            "Schema::table('orders', fn (Blueprint \$t) => \$t->index('status'));",
            [new Table('orders', [], [new Index('i', ['status'])])],
        )))->toBeEmpty();
    });

    it('leaves unique indexes alone, since they enforce something extra', function (): void {
        expect((new RedundantIndexRule)->analyze(usageContext(
            "Schema::table('orders', fn (Blueprint \$t) => \$t->unique('status'));",
            [new Table('orders', [], [new Index('i', ['status', 'type'])])],
        )))->toBeEmpty();
    });

    it('says nothing when there is no live table to compare against', function (): void {
        expect((new RedundantIndexRule)->analyze(usageContext(
            "Schema::table('orders', fn (Blueprint \$t) => \$t->index('status'));",
            [],
        )))->toBeEmpty();
    });

    it('matches a multi-column prefix too', function (): void {
        $findings = (new RedundantIndexRule)->analyze(usageContext(
            "Schema::table('orders', fn (Blueprint \$t) => \$t->index(['status', 'type']));",
            [new Table('orders', [], [new Index('wide', ['status', 'type', 'created_at'])])],
        ));

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->explanation)->toContain('wide');
    });
});
