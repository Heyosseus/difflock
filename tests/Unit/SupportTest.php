<?php

declare(strict_types=1);

use Difflock\Console\Renderers\Text;
use Difflock\Contracts\MigrationRule;
use Difflock\Migration\IgnoreList;
use Difflock\Migration\MigrationContext;
use Difflock\Migration\MigrationFinding;
use Difflock\Migration\Thresholds;
use Difflock\Risk\RiskLevel;
use Difflock\RuleRegistry;
use Difflock\Support\Bytes;
use Difflock\Support\TypeFamily;
use Illuminate\Container\Container;

it('renders byte counts a person can read', function (int $bytes, string $expected): void {
    expect(Bytes::human($bytes))->toBe($expected);
})->with([
    [0, '0 B'],
    [512, '512 B'],
    [2048, '2.0 KB'],
    [15 * 1024 * 1024, '15 MB'],
    [3 * 1024 * 1024 * 1024, '3.0 GB'],
    [-1, '0 B'],
]);

it('groups blueprint methods and database types into the same families', function (): void {
    expect(TypeFamily::ofBlueprint('string'))->toBe('text')
        ->and(TypeFamily::ofBlueprint('bigInteger'))->toBe('integer')
        ->and(TypeFamily::ofBlueprint('somethingNobodyHasHeardOf'))->toBeNull()
        ->and(TypeFamily::ofDatabase('character varying(320)'))->toBe('text')
        ->and(TypeFamily::ofDatabase('VARCHAR(255)'))->toBe('text')
        ->and(TypeFamily::ofDatabase('timestamp without time zone'))->toBe('datetime')
        ->and(TypeFamily::ofDatabase('int8'))->toBe('integer')
        ->and(TypeFamily::ofDatabase('hstore'))->toBeNull();
});

it('calls a spelling difference a spelling difference, not a type change', function (): void {
    expect(TypeFamily::changes('string', 'character varying(255)'))->toBeFalse()
        ->and(TypeFamily::changes('integer', 'varchar(50)'))->toBeTrue()
        ->and(TypeFamily::changes('string', 'hstore'))->toBeFalse()
        ->and(TypeFamily::changes('unknownMethod', 'varchar'))->toBeFalse();
});

it('treats an unknown row count as neither large nor empty', function (): void {
    $thresholds = new Thresholds(100, 1_000);

    expect($thresholds->isLarge(null))->toBeFalse()
        ->and($thresholds->isMedium(null))->toBeFalse()
        ->and($thresholds->isMedium(100))->toBeTrue()
        ->and($thresholds->isLarge(999))->toBeFalse()
        ->and($thresholds->isLarge(1_000))->toBeTrue()
        ->and(Thresholds::format(8_421_392))->toBe('8,421,392');
});

it('matches ignores by rule, table and migration, with wildcards', function (): void {
    $finding = new MigrationFinding(
        rule: 'drop-column',
        risk: RiskLevel::Critical,
        migration: '2019_01_01_000000_old',
        message: 'm',
        explanation: 'e',
        table: 'telescope_entries',
    );

    expect((new IgnoreList)->allows($finding))->toBeTrue()
        ->and((new IgnoreList(rules: ['drop-*']))->allows($finding))->toBeFalse()
        ->and((new IgnoreList(tables: ['telescope_*']))->allows($finding))->toBeFalse()
        ->and((new IgnoreList(migrations: ['2019_*']))->allows($finding))->toBeFalse()
        ->and((new IgnoreList(rules: ['add-index']))->allows($finding))->toBeTrue()
        ->and((new IgnoreList(tables: ['telescope_*']))->ignoresTable('telescope_entries'))->toBeTrue();
});

it('builds an ignore list out of whatever configuration holds', function (): void {
    $list = IgnoreList::fromConfig([
        'rules' => ['drop-table', 42, ''],
        'tables' => 'not an array',
    ]);

    expect($list->rules)->toBe(['drop-table'])
        ->and($list->tables)->toBe([])
        ->and($list->migrations)->toBe([]);
});

it('resolves registered rules and keeps the last of each identifier', function (): void {
    $first = new class implements MigrationRule
    {
        public function identifier(): string
        {
            return 'shared';
        }

        public function analyze(MigrationContext $context): array
        {
            return [];
        }
    };

    $second = new class implements MigrationRule
    {
        public function identifier(): string
        {
            return 'shared';
        }

        public function analyze(MigrationContext $context): array
        {
            return [];
        }
    };

    $resolved = (new RuleRegistry([$first]))->add($second)->add('NoSuchClass')->add(stdClass::class)
        ->resolve(new Container);

    expect($resolved)->toHaveCount(1)
        ->and($resolved[0])->toBe($second);
});

it('wraps prose to a readable width and indents every line', function (): void {
    $lines = Text::wrap(str_repeat('word ', 40), '    ');

    expect($lines)->not->toHaveCount(1)
        ->and($lines)->each->toStartWith('    ')
        ->and(Text::pad('x', 4))->toBe('x   ')
        ->and(Text::divider(3))->toBe('───');
});
