<?php

declare(strict_types=1);

use Difflock\Migration\MigrationFinding;
use Difflock\Migration\Subject;
use Difflock\Risk\RiskLevel;
use Difflock\Risk\RiskSummary;

function finding(RiskLevel $risk, string $rule = 'test'): MigrationFinding
{
    return new MigrationFinding(
        rule: $rule,
        risk: $risk,
        migration: '2026_01_01_000000_test',
        message: 'message',
        explanation: 'explanation',
    );
}

it('orders the levels from safe to critical', function (): void {
    expect(array_map(fn (RiskLevel $l): int => $l->rank(), RiskLevel::ascending()))
        ->toBe([0, 1, 2, 3, 4]);
});

it('answers whether one level is at least another', function (): void {
    expect(RiskLevel::High->atLeast(RiskLevel::Medium))->toBeTrue()
        ->and(RiskLevel::High->atLeast(RiskLevel::High))->toBeTrue()
        ->and(RiskLevel::Medium->atLeast(RiskLevel::High))->toBeFalse();
});

it('takes the more serious of two levels', function (): void {
    expect(RiskLevel::Low->max(RiskLevel::Critical))->toBe(RiskLevel::Critical)
        ->and(RiskLevel::Critical->max(RiskLevel::Low))->toBe(RiskLevel::Critical)
        ->and(RiskLevel::Safe->max(RiskLevel::Safe))->toBe(RiskLevel::Safe);
});

it('gives every level a label, a colour and a glyph', function (RiskLevel $level): void {
    expect($level->label())->toBe(strtoupper($level->value))
        ->and($level->colour())->not->toBeEmpty()
        ->and($level->glyph())->not->toBeEmpty();
})->with(RiskLevel::ascending());

it('counts findings by level and reports the worst', function (): void {
    $summary = RiskSummary::of([
        finding(RiskLevel::Low),
        finding(RiskLevel::High),
        finding(RiskLevel::High),
    ]);

    expect($summary->total)->toBe(3)
        ->and($summary->highest)->toBe(RiskLevel::High)
        ->and($summary->count(RiskLevel::High))->toBe(2)
        ->and($summary->count(RiskLevel::Low))->toBe(1)
        ->and($summary->count(RiskLevel::Critical))->toBe(0)
        ->and($summary->counts)->toHaveKeys(['safe', 'low', 'medium', 'high', 'critical']);
});

it('crosses a threshold only when something reaches it', function (): void {
    $summary = RiskSummary::of([finding(RiskLevel::Medium)]);

    expect($summary->crosses(RiskLevel::Low))->toBeTrue()
        ->and($summary->crosses(RiskLevel::Medium))->toBeTrue()
        ->and($summary->crosses(RiskLevel::High))->toBeFalse();
});

it('never crosses a threshold with no findings, even the lowest one', function (): void {
    expect(RiskSummary::of([])->crosses(RiskLevel::Safe))->toBeFalse()
        ->and(RiskSummary::of([])->highest)->toBe(RiskLevel::Safe);
});

it('names the subject with a key saying what kind of thing it is', function (): void {
    $column = new MigrationFinding(
        rule: 'drop-column',
        risk: RiskLevel::Critical,
        migration: 'm',
        message: 'message',
        explanation: 'explanation',
        table: 'users',
        subject: 'email',
        subjectType: Subject::Column,
        destructive: true,
        reversible: false,
    );

    expect($column->toArray())->toMatchArray([
        'rule' => 'drop-column',
        'risk' => 'critical',
        'table' => 'users',
        'column' => 'email',
        'destructive' => true,
        'reversible' => false,
    ]);

    expect(finding(RiskLevel::Low)->toArray())->not->toHaveKey('column');
});
