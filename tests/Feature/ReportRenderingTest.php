<?php

declare(strict_types=1);

use Difflock\Console\Renderers\ReportRenderer;
use Difflock\Migration\MigrationFinding;
use Difflock\Migration\MigrationReport;
use Difflock\Migration\Parser\ParsedMigration;
use Difflock\Risk\RiskLevel;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

function reportOf(MigrationFinding ...$findings): MigrationReport
{
    return new MigrationReport([new ParsedMigration('m', 'm.php')], array_values($findings));
}

function shared(int $index, RiskLevel $risk = RiskLevel::High, string $rule = 'foreign-key'): MigrationFinding
{
    return new MigrationFinding(
        rule: $rule,
        risk: $risk,
        migration: 'migration_'.$index,
        message: 'FOREIGN KEY table_'.$index.'.user_id ON DELETE CASCADE',
        explanation: 'A cascading delete removes child rows whenever a parent row is deleted.',
        suggestion: 'Consider restrictOnDelete().',
        table: 'table_'.$index,
        line: $index,
    );
}

function render(MigrationReport $report, bool $verbose = false): string
{
    $output = new BufferedOutput(
        $verbose ? OutputInterface::VERBOSITY_VERBOSE : OutputInterface::VERBOSITY_NORMAL,
    );

    (new ReportRenderer)->render($output, $report);

    return $output->fetch();
}

/**
 * The defect this rewrite exists to fix: a real application produced 124 findings
 * from one rule, and the old renderer printed the identical explanation with every
 * one of them.
 */
it('prints a shared explanation once, however many findings share it', function (): void {
    $findings = array_map(shared(...), range(1, 124));

    $output = render(reportOf(...$findings), verbose: true);

    expect(substr_count($output, 'A cascading delete removes child rows'))->toBe(1)
        ->and(substr_count($output, 'Consider restrictOnDelete'))->toBe(1)
        ->and($output)->toContain('124 findings');
});

it('bounds the summary however many findings there are', function (): void {
    $lines = substr_count(render(reportOf(...array_map(shared(...), range(1, 200)))), "\n");

    expect($lines)->toBeLessThan(25);
});

it('points at the ways to see more', function (): void {
    $output = render(reportOf(...array_map(shared(...), range(1, 124))));

    expect($output)->toContain('-v')
        ->toContain('--rule=')
        ->toContain('difflock:report')
        ->toContain('124 findings');
});

it('lists every occurrence when asked to be verbose', function (): void {
    $output = render(reportOf(...array_map(shared(...), range(1, 124))), verbose: true);

    expect($output)->toContain('table_124.user_id')
        ->and($output)->not->toContain('more. Run with -v');
});

it('keeps a group readable rather than short', function (): void {
    $lines = substr_count(render(reportOf(...array_map(shared(...), range(1, 124)))), "\n");

    // The old renderer printed roughly nine lines per finding — over a thousand for
    // this group alone. The number matters less than the order of magnitude.
    expect($lines)->toBeLessThan(40);
});

it('prints each finding in full when the explanations genuinely differ', function (): void {
    $output = render(reportOf(
        new MigrationFinding('drop-column', RiskLevel::Critical, 'm1', 'DROP COLUMN a.b',
            'The table holds 12 rows.', table: 'a', line: 1),
        new MigrationFinding('drop-column', RiskLevel::Critical, 'm2', 'DROP COLUMN c.d',
            'The table holds 8,000,000 rows.', table: 'c', line: 2),
    ), verbose: true);

    expect($output)->toContain('12 rows')
        ->and($output)->toContain('8,000,000 rows');
});

it('separates the same rule reported at different levels', function (): void {
    $output = render(reportOf(
        shared(1, RiskLevel::High, 'add-index'),
        shared(2, RiskLevel::Low, 'add-index'),
    ));

    expect($output)->toContain('HIGH')
        ->and($output)->toContain('LOW')
        ->and(substr_count($output, 'add-index'))->toBeGreaterThanOrEqual(2);
});

it('never hides the risk tally or the accepted count', function (): void {
    $report = new MigrationReport(
        [new ParsedMigration('m', 'm.php')],
        [shared(1)],
        true,
        [shared(2)],
    );

    expect(render($report, verbose: true))->toContain('Risk')
        ->toContain('1 previously accepted finding');
});

describe('filters', function (): void {
    beforeEach(function (): void {
        Illuminate\Support\Facades\Schema::create('users', function (Illuminate\Database\Schema\Blueprint $t): void {
            $t->id();
            $t->string('legacy_token')->nullable();
        });

        Illuminate\Support\Facades\Schema::create('orders', function (Illuminate\Database\Schema\Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('customer_id');
        });

        config()->set('difflock.migrations.paths', [fixtures()]);
    });

    it('narrows by rule', function (): void {
        [, $output] = runCommand('difflock:lint', ['--all' => true, '--rule' => 'drop-column']);

        expect($output)->toContain('DROP COLUMN users.legacy_token')
            ->and($output)->not->toContain('ADD INDEX');
    });

    it('narrows by table', function (): void {
        [, $output] = runCommand('difflock:lint', ['--all' => true, '--table' => 'orders']);

        expect($output)->toContain('ADD INDEX orders')
            ->and($output)->not->toContain('DROP COLUMN users');
    });

    it('narrows by risk', function (): void {
        [, $output] = runCommand('difflock:lint', ['--all' => true, '--risk' => 'critical']);

        expect($output)->toContain('DROP COLUMN users.legacy_token')
            ->and($output)->not->toContain('ADD INDEX');
    });

    /**
     * A filter that could lower the exit code would be a way to make a red build
     * green by naming a different rule, which is the opposite of the point.
     */
    it('cannot turn a failing run into a passing one', function (): void {
        [$unfiltered] = runCommand('difflock:lint', ['--all' => true]);
        [$filtered] = runCommand('difflock:lint', ['--all' => true, '--rule' => 'add-index']);

        expect($unfiltered)->toBe(1)
            ->and($filtered)->toBe(1);
    });

    it('ignores a risk filter that names nothing', function (): void {
        [$exit, $output] = runCommand('difflock:lint', ['--all' => true, '--risk' => 'nonsense']);

        expect($exit)->toBe(1)
            ->and($output)->toContain('DROP COLUMN users.legacy_token');
    });
});
