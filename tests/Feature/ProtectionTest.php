<?php

declare(strict_types=1);

use Difflock\Facades\Difflock;
use Difflock\Protection\MigrationGuard;
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

it('blocks a migration that drops a column', function (): void {
    $decision = app(MigrationGuard::class)->inspect();

    expect($decision->blocked)->toBeTrue()
        ->and($decision->allowed())->toBeFalse()
        ->and($decision->threshold)->toBe(RiskLevel::Critical)
        ->and($decision->enforced)->toBeTrue()
        ->and($decision->report->highestRisk())->toBe(RiskLevel::Critical);
});

it('allows migrations that stay under the block level', function (): void {
    $decision = app(MigrationGuard::class)->inspect([fixtures('safe')]);

    expect($decision->blocked)->toBeFalse()
        ->and($decision->allowed())->toBeTrue();
});

it('blocks nothing when protection is switched off', function (): void {
    config()->set('difflock.protection.enabled', false);

    $decision = app(MigrationGuard::class)->inspect();

    expect($decision->blocked)->toBeFalse()
        ->and($decision->enforced)->toBeFalse()
        ->and($decision->report->highestRisk())->toBe(RiskLevel::Critical);
});

it('blocks at a lower level when configured to', function (): void {
    config()->set('difflock.protection.block_on', 'medium');

    expect(app(MigrationGuard::class)->inspect([fixtures('safe')])->blocked)->toBeFalse();

    config()->set('difflock.protection.block_on', 'safe');

    expect(app(MigrationGuard::class)->inspect()->blocked)->toBeTrue();
});

it('falls back to critical when the configured block level names nothing', function (): void {
    config()->set('difflock.protection.block_on', 'apocalyptic');

    expect(app(MigrationGuard::class)->policy()->blockOn)->toBe(RiskLevel::Critical);
});

it('reaches the guard through the facade too', function (): void {
    expect(Difflock::guard()->blocked)->toBeTrue();
});

describe('difflock:migrate', function (): void {
    it('refuses to run a blocked migration and leaves the schema alone', function (): void {
        [$exit, $output] = runCommand('difflock:migrate');

        expect($exit)->toBe(1)
            ->and($output)->toContain('Migration blocked')
            ->toContain('DROP COLUMN users.legacy_token')
            ->and(Schema::hasColumn('users', 'legacy_token'))->toBeTrue();
    });

    it('never writes during a dry run, even when nothing would have blocked it', function (): void {
        [$exit, $output] = runCommand('difflock:migrate', ['--dry-run' => true, '--path' => [fixtures('safe')], '--realpath' => true]);

        expect($exit)->toBe(0)
            ->and($output)->toContain('Dry run')
            ->toContain('Nothing was written')
            ->and(Schema::hasTable('receipts'))->toBeFalse();
    });

    it('never writes during a dry run of a blocked migration either', function (): void {
        [$exit, $output] = runCommand('difflock:migrate', ['--dry-run' => true]);

        expect($exit)->toBe(0)
            ->and($output)->toContain('Dry run')
            ->and(Schema::hasColumn('users', 'legacy_token'))->toBeTrue();
    });

    it('runs a safe migration through to the database', function (): void {
        [$exit] = runCommand('difflock:migrate', ['--path' => [fixtures('safe')], '--realpath' => true, '--force' => true]);

        expect($exit)->toBe(0)
            ->and(Schema::hasTable('receipts'))->toBeTrue();
    });

    it('runs a blocked migration when explicitly allowed', function (): void {
        [$exit] = runCommand('difflock:migrate', [
            '--allow-risky' => true,
            '--path' => [fixtures('safe')], '--realpath' => true,
            '--force' => true,
        ]);

        expect($exit)->toBe(0)
            ->and(Schema::hasTable('receipts'))->toBeTrue();
    });

    it('says out loud when protection is switched off', function (): void {
        config()->set('difflock.protection.enabled', false);

        [, $output] = runCommand('difflock:migrate', ['--path' => [fixtures('safe')], '--realpath' => true, '--force' => true]);

        expect($output)->toContain('protection is switched off');
    });

    it('emits a JSON decision and still blocks', function (): void {
        [$exit, $output] = runCommand('difflock:migrate', ['--format' => 'json'], decorated: true);

        $document = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(1)
            ->and($output)->not->toContain("\033")
            ->and($document)->toMatchArray([
                'status' => 'failed',
                'dry_run' => false,
                'migrated' => false,
                'blocked' => true,
                'threshold' => 'critical',
                'protection_enforced' => true,
            ])
            ->and(Schema::hasColumn('users', 'legacy_token'))->toBeTrue();
    });

    it('emits one parseable document when it actually migrates', function (): void {
        [$exit, $output] = runCommand('difflock:migrate', [
            '--format' => 'json',
            '--path' => [fixtures('safe')], '--realpath' => true,
            '--force' => true,
        ]);

        $document = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(0)
            ->and($document['migrated'])->toBeTrue()
            ->and(Schema::hasTable('receipts'))->toBeTrue();
    });

    it('emits a JSON dry run without migrating', function (): void {
        [$exit, $output] = runCommand('difflock:migrate', [
            '--format' => 'json',
            '--dry-run' => true,
            '--path' => [fixtures('safe')], '--realpath' => true,
        ]);

        $document = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(0)
            ->and($document['dry_run'])->toBeTrue()
            ->and($document['migrated'])->toBeFalse()
            ->and(Schema::hasTable('receipts'))->toBeFalse();
    });

    it('refuses to run when Difflock is disabled', function (): void {
        config()->set('difflock.enabled', false);

        [$exit] = runCommand('difflock:migrate');

        expect($exit)->toBe(2)
            ->and(Schema::hasColumn('users', 'legacy_token'))->toBeTrue();
    });

    it('reports a failure to analyse rather than migrating anyway', function (): void {
        config()->set('difflock.migrations.paths', ['/definitely/not/a/directory']);

        [$exit, $output] = runCommand('difflock:migrate', ['--path' => ['/definitely/not/a/directory'], '--realpath' => true]);

        expect($exit)->toBe(0)
            ->and($output)->toContain('No migrations were found to analyse');
    });
});
