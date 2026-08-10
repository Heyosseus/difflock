<?php

declare(strict_types=1);

use Difflock\Contracts\SchemaInspector;
use Difflock\Schema\Baseline;
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

describe('difflock:lint', function (): void {
    it('reports findings and fails at the configured threshold', function (): void {
        [$exit, $output] = runCommand('difflock:lint');

        expect($exit)->toBe(1)
            ->and($output)->toContain('DROP COLUMN users.legacy_token')
            ->toContain('CRITICAL')
            ->toContain('2026_08_10_120000_remove_legacy_token')
            ->toContain('Risk');
    });

    it('passes when nothing reaches the threshold', function (): void {
        [$exit, $output] = runCommand('difflock:lint', ['--fail-on' => 'critical', '--path' => [fixtures('safe')]]);

        expect($exit)->toBe(0)
            ->and($output)->toContain('1 migration analysed');
    });

    it('fails at a lower threshold on the same migrations', function (): void {
        [$exit] = runCommand('difflock:lint', ['--fail-on' => 'low', '--path' => [fixtures('safe')]]);

        expect($exit)->toBe(0);

        [$exit] = runCommand('difflock:lint', ['--fail-on' => 'low']);

        expect($exit)->toBe(1);
    });

    it('rejects a threshold that names nothing', function (): void {
        [$exit, $output] = runCommand('difflock:lint', ['--fail-on' => 'catastrophic']);

        expect($exit)->toBe(2)
            ->and($output)->toContain('no risk level called');
    });

    it('emits JSON with no ANSI in it, even when the terminal is decorated', function (): void {
        [$exit, $output] = runCommand('difflock:lint', ['--format' => 'json'], decorated: true);

        expect($exit)->toBe(1)
            ->and($output)->not->toContain("\033");

        $document = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);

        expect($document)->toMatchArray(['status' => 'failed', 'risk' => 'critical', 'threshold' => 'critical'])
            ->and($document['findings'][0])->toMatchArray([
                'rule' => 'drop-column',
                'risk' => 'critical',
                'table' => 'users',
                'column' => 'legacy_token',
                'destructive' => true,
                'reversible' => false,
            ]);
    });

    it('reads the same output without colour', function (): void {
        [, $plain] = runCommand('difflock:lint');
        [, $coloured] = runCommand('difflock:lint', decorated: true);

        expect($plain)->toContain('DROP COLUMN users.legacy_token')
            ->and($coloured)->toContain("\033")
            ->and($plain)->not->toContain("\033");
    });

    it('refuses to run when Difflock is disabled', function (): void {
        config()->set('difflock.enabled', false);

        [$exit, $output] = runCommand('difflock:lint');

        expect($exit)->toBe(2)
            ->and($output)->toContain('disabled');
    });
});

describe('difflock:diff', function (): void {
    it('records a baseline and then reports no drift', function (): void {
        [$exit, $output] = runCommand('difflock:diff', ['--save' => true]);

        expect($exit)->toBe(0)
            ->and($output)->toContain('Baseline recorded')
            ->and(app(Baseline::class)->exists())->toBeTrue();

        [$exit, $output] = runCommand('difflock:diff');

        expect($exit)->toBe(0)
            ->and($output)->toContain('No differences');
    });

    it('reports what drifted after the schema changes', function (): void {
        runCommand('difflock:diff', ['--save' => true]);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 50)->nullable();
        });

        Schema::drop('orders');

        [$exit, $output] = runCommand('difflock:diff');

        expect($exit)->toBe(1)
            ->and($output)->toContain('+ phone')
            ->toContain('orders')
            ->toContain('2 changes detected');
    });

    it('emits the diff as JSON', function (): void {
        runCommand('difflock:diff', ['--save' => true]);

        Schema::table('users', fn (Blueprint $table) => $table->string('phone')->nullable());

        [$exit, $output] = runCommand('difflock:diff', ['--format' => 'json'], decorated: true);

        $document = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(1)
            ->and($output)->not->toContain("\033")
            ->and($document['status'])->toBe('failed')
            ->and($document['schema']['changes'])->toBe(1)
            ->and($document['schema']['tables'][0]['columns'][0])
            ->toMatchArray(['name' => 'phone', 'change' => 'added']);
    });

    it('records a baseline as JSON', function (): void {
        [$exit, $output] = runCommand('difflock:diff', ['--save' => true, '--format' => 'json']);

        $document = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(0)
            ->and($document['status'])->toBe('passed')
            ->and($document['tables'])->toBe(2);
    });

    it('refuses to compare against a baseline nobody recorded', function (): void {
        [$exit, $output] = runCommand('difflock:diff');

        expect($exit)->toBe(2)
            ->and($output)->toContain('No schema baseline');
    });

    it('refuses to read a baseline it does not understand', function (): void {
        $path = app(Baseline::class)->path();

        @mkdir(dirname($path), 0o777, true);
        file_put_contents($path, 'not json at all');

        [$exit, $output] = runCommand('difflock:diff');

        expect($exit)->toBe(2)
            ->and($output)->toContain('could not be read');
    });

    it('compares two connections', function (): void {
        config()->set('database.connections.other', config('database.connections.testing'));

        [$exit] = runCommand('difflock:diff', ['--from' => 'testing', '--to' => 'testing']);

        expect($exit)->toBe(0);
    });

    it('reports a connection it cannot reach', function (): void {
        config()->set('database.connections.broken', [
            'driver' => 'sqlite',
            'database' => '/definitely/not/a/path/db.sqlite',
        ]);

        [$exit, $output] = runCommand('difflock:diff', ['--to' => 'broken', '--from' => 'testing']);

        expect($exit)->toBe(2)
            ->and($output)->toContain('could not read the schema');
    });
});

describe('difflock:check', function (): void {
    it('says drift was not checked when no baseline was recorded', function (): void {
        [$exit, $output] = runCommand('difflock:check');

        expect($exit)->toBe(1)
            ->and($output)->toContain('No baseline recorded')
            ->toContain('Result: FAIL');
    });

    it('passes when the schema matches and nothing crosses the threshold', function (): void {
        runCommand('difflock:diff', ['--save' => true]);
        config()->set('difflock.migrations.paths', [fixtures('safe')]);

        [$exit, $output] = runCommand('difflock:check');

        expect($exit)->toBe(0)
            ->and($output)->toContain('No drift detected')
            ->toContain('Result: PASS');
    });

    it('fails when the schema has drifted even if the migrations are clean', function (): void {
        runCommand('difflock:diff', ['--save' => true]);
        config()->set('difflock.migrations.paths', [fixtures('safe')]);

        Schema::table('users', fn (Blueprint $table) => $table->string('phone')->nullable());

        [$exit, $output] = runCommand('difflock:check');

        expect($exit)->toBe(1)
            ->and($output)->toContain('1 difference from the baseline')
            ->toContain('Schema drift');
    });

    it('fails when the baseline exists and cannot be read', function (): void {
        $path = app(Baseline::class)->path();

        @mkdir(dirname($path), 0o777, true);
        file_put_contents($path, '{"difflock": 99, "schema": {}}');

        [$exit, $output] = runCommand('difflock:check');

        expect($exit)->toBe(1)
            ->and($output)->toContain('baseline could not be read');
    });

    it('prints only the summary and the failures under --ci', function (): void {
        runCommand('difflock:diff', ['--save' => true]);
        config()->set('difflock.migrations.paths', [fixtures('safe')]);

        [$exit, $output] = runCommand('difflock:check', ['--ci' => true]);

        expect($exit)->toBe(0)
            ->and($output)->toContain('Difflock CI')
            ->toContain('No drift detected')
            ->and($output)->not->toContain('Risk');
    });

    it('emits a combined JSON document', function (): void {
        runCommand('difflock:diff', ['--save' => true]);

        [$exit, $output] = runCommand('difflock:check', ['--format' => 'json'], decorated: true);

        $document = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(1)
            ->and($output)->not->toContain("\033")
            ->and($document)->toHaveKeys(['difflock', 'status', 'risk', 'threshold', 'schema', 'migrations'])
            ->and($document['status'])->toBe('failed')
            ->and($document['schema']['changes'])->toBe(0)
            ->and($document['migrations']['risk'])->toBe('critical');
    });

    it('reports the schema as null when drift was not checked', function (): void {
        [$exit, $output] = runCommand('difflock:check', ['--format' => 'json']);

        $document = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(1)
            ->and($document['schema'])->toBeNull();
    });

    it('rejects a threshold that names nothing', function (): void {
        [$exit] = runCommand('difflock:check', ['--fail-on' => 'nope']);

        expect($exit)->toBe(2);
    });

    it('refuses to run when Difflock is disabled', function (): void {
        config()->set('difflock.enabled', false);

        expect(runCommand('difflock:check')[0])->toBe(2);
    });
});

describe('difflock', function (): void {
    it('reports both halves and points at the other commands', function (): void {
        runCommand('difflock:diff', ['--save' => true]);
        config()->set('difflock.migrations.paths', [fixtures('safe')]);

        [$exit, $output] = runCommand('difflock');

        expect($exit)->toBe(0)
            ->and($output)->toContain('Difflock')
            ->toContain('No drift detected')
            ->toContain('Result: PASS')
            ->toContain('difflock:lint');
    });

    it('agrees with difflock:check about whether the run failed', function (): void {
        expect(runCommand('difflock')[0])->toBe(runCommand('difflock:check')[0]);
    });

    it('emits the same JSON document as difflock:check', function (): void {
        runCommand('difflock:diff', ['--save' => true]);

        [, $overview] = runCommand('difflock', ['--format' => 'json']);
        [, $check] = runCommand('difflock:check', ['--format' => 'json']);

        expect(trim($overview))->toBe(trim($check));
    });

    it('rejects a threshold that names nothing', function (): void {
        expect(runCommand('difflock', ['--fail-on' => 'nope'])[0])->toBe(2);
    });

    it('refuses to run when Difflock is disabled', function (): void {
        config()->set('difflock.enabled', false);

        expect(runCommand('difflock')[0])->toBe(2);
    });

    it('reports a connection it cannot reach', function (): void {
        config()->set('database.connections.broken', ['driver' => 'sqlite', 'database' => '/no/such/db.sqlite']);
        config()->set('difflock.migrations.paths', []);

        runCommand('difflock:diff', ['--save' => true]);

        [$exit, $output] = runCommand('difflock', ['--connection' => 'broken']);

        expect($exit)->toBe(1)
            ->and($output)->toContain('baseline could not be read');
    });
});

it('inspects a named connection rather than the default when asked', function (): void {
    config()->set('difflock.connection', 'testing');

    expect(app(SchemaInspector::class)->inspect()->connection)->toBe('testing');
});
