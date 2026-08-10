<?php

declare(strict_types=1);

use Difflock\Contracts\MigrationAnalyzer;
use Difflock\Migration\AcceptedFindings;
use Difflock\Migration\MigrationScope;
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
    config()->set('difflock.accepted', sys_get_temp_dir().'/difflock-tests/accepted-'.getmypid().'.json');
});

afterEach(function (): void {
    $path = config('difflock.accepted');

    if (is_string($path) && is_file($path)) {
        unlink($path);
    }
});

it('fails the gate on an untouched codebase', function (): void {
    expect(runCommand('difflock:lint', ['--all' => true])[0])->toBe(1);
});

it('accepts the whole backlog and then passes', function (): void {
    [$exit, $output] = runCommand('difflock:lint', ['--all' => true, '--accept' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Accepted')
        ->and(app(AcceptedFindings::class)->exists())->toBeTrue();

    [$exit, $output] = runCommand('difflock:lint', ['--all' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('previously accepted');
});

it('still fails on a finding nobody accepted', function (): void {
    runCommand('difflock:lint', ['--all' => true, '--accept' => true]);

    expect(runCommand('difflock:lint', ['--all' => true])[0])->toBe(0);

    // A new migration nobody has seen, doing the most destructive thing there is.
    $path = fixtures('new');
    @mkdir($path, 0o777, true);
    file_put_contents($path.'/2027_01_01_000000_drop_orders.php', <<<'PHP'
    <?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration
    {
        public function up(): void
        {
            Schema::dropIfExists('orders');
        }
    };
    PHP);

    config()->set('difflock.migrations.paths', [fixtures(), $path]);

    [$exit, $output] = runCommand('difflock:lint', ['--all' => true]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('DROP TABLE orders');

    unlink($path.'/2027_01_01_000000_drop_orders.php');
    rmdir($path);
});

it('identifies a finding by what it is about, not by where it sits', function (): void {
    $findings = app(MigrationAnalyzer::class)->analyze(MigrationScope::All)->findings;

    $drop = collect($findings)->firstWhere('rule', 'drop-column');

    expect($drop?->fingerprint())->toBe('drop-column|2026_08_10_120000_remove_legacy_token|users|legacy_token')
        ->and($drop?->fingerprint())->not->toContain((string) $drop?->line)
        ->and($drop?->fingerprint())->not->toContain(RiskLevel::Critical->value);
});

it('is idempotent, and does not forget the backlog on a second run', function (): void {
    runCommand('difflock:lint', ['--all' => true, '--accept' => true]);

    $first = app(AcceptedFindings::class)->fingerprints();

    runCommand('difflock:lint', ['--all' => true, '--accept' => true]);

    expect(app(AcceptedFindings::class)->fingerprints())->toBe($first)
        ->and($first)->not->toBeEmpty();
});

it('counts accepted findings in the JSON report without listing them', function (): void {
    runCommand('difflock:lint', ['--all' => true, '--accept' => true]);

    [$exit, $output] = runCommand('difflock:lint', ['--all' => true, '--format' => 'json']);

    $document = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($document['status'])->toBe('passed')
        ->and($document['findings'])->toBeEmpty()
        ->and($document['accepted'])->toBeGreaterThan(0);
});

it('reports what it accepted as JSON', function (): void {
    [$exit, $output] = runCommand('difflock:lint', ['--all' => true, '--accept' => true, '--format' => 'json']);

    $document = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($document['accepted'])->toBeGreaterThan(0)
        ->and($document['file'])->toBe(app(AcceptedFindings::class)->path());
});

it('refuses to read an accepted file it cannot parse, rather than accepting everything', function (): void {
    $path = config('difflock.accepted');

    @mkdir(dirname($path), 0o777, true);
    file_put_contents($path, 'not json');

    expect(fn () => app(AcceptedFindings::class)->fingerprints())
        ->toThrow(Difflock\Exceptions\InvalidSnapshot::class);

    file_put_contents($path, '{"difflock":1}');

    expect(fn () => app(AcceptedFindings::class)->fingerprints())
        ->toThrow(Difflock\Exceptions\InvalidSnapshot::class, 'no accepted findings');
});

it('audits everything already applied when nothing is pending', function (): void {
    // Every fixture migration recorded as run, so nothing is pending.
    runCommand('migrate', ['--path' => [fixtures()], '--realpath' => true, '--force' => true]);

    [$exit, $output] = runCommand('difflock:lint');

    // Short fragments on purpose: the notice is word-wrapped, so anything long
    // enough to cross the wrap boundary would be asserting on the typesetting.
    expect($output)->toContain('Nothing is pending')
        ->and($output)->toContain('audit of every migration')
        ->and($output)->toContain('DROP COLUMN users.legacy_token')
        ->and($exit)->toBe(1);
});

it('says where it looked when there are no migrations at all', function (): void {
    [$exit, $output] = runCommand('difflock:lint', ['--path' => [fixtures('nowhere')], '--realpath' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('No migrations were found to analyse')
        ->and($output)->toContain('--path');
});
