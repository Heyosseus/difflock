<?php

declare(strict_types=1);

use Difflock\Schema\Column;
use Difflock\Schema\DatabaseSchema;
use Difflock\Schema\Table;
use Difflock\Support\SecretHeuristics;
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

afterEach(function (): void {
    $report = storage_path('difflock');

    if (is_dir($report)) {
        foreach (glob($report.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($report);
    }
});

describe('difflock:report', function (): void {
    it('writes a self-contained html file and keeps the check exit code', function (): void {
        [$exit, $output] = runCommand('difflock:report');

        $path = storage_path('difflock/report.html');

        expect($exit)->toBe(1)
            ->and($output)->toContain('Written to')
            ->and(file_exists($path))->toBeTrue();

        $html = file_get_contents($path);

        expect($html)->toStartWith('<!doctype html>')
            ->toContain('DROP COLUMN users.legacy_token')
            ->toContain('drop-column')
            ->toContain('FAIL');

        // Nothing may be fetched: a CI artifact opened from disk has no network.
        expect($html)->not->toContain('<script')
            ->and($html)->not->toContain('http://')
            ->and($html)->not->toContain('https://');
    });

    it('escapes everything, because none of it is Difflock\'s text', function (): void {
        Schema::create('x<script>', fn (Blueprint $table) => $table->id());

        runCommand('difflock:diff', ['--save' => true]);
        Schema::drop('x<script>');

        runCommand('difflock:report');

        $html = file_get_contents(storage_path('difflock/report.html'));

        expect($html)->toContain('x&lt;script&gt;')
            ->and($html)->not->toContain('<script>');
    });

    it('writes wherever it is told', function (): void {
        [$exit] = runCommand('difflock:report', ['--output' => 'storage/difflock/custom.html']);

        expect($exit)->toBe(1)
            ->and(file_exists(base_path('storage/difflock/custom.html')))->toBeTrue();
    });

    it('writes the same json document difflock:check emits', function (): void {
        runCommand('difflock:report', ['--format' => 'json']);

        $document = json_decode(file_get_contents(storage_path('difflock/report.json')), true, flags: JSON_THROW_ON_ERROR);

        expect($document)->toHaveKeys(['difflock', 'status', 'risk', 'threshold', 'schema', 'migrations'])
            ->and($document['status'])->toBe('failed');
    });

    it('passes and says so when nothing crosses the threshold', function (): void {
        config()->set('difflock.migrations.paths', [fixtures('safe')]);
        runCommand('difflock:diff', ['--save' => true]);

        [$exit] = runCommand('difflock:report');

        expect($exit)->toBe(0)
            ->and(file_get_contents(storage_path('difflock/report.html')))->toContain('PASS');
    });

    it('refuses to run when Difflock is disabled', function (): void {
        config()->set('difflock.enabled', false);

        expect(runCommand('difflock:report')[0])->toBe(2);
    });
});

describe('secret heuristics', function (): void {
    it('recognises credentials by shape', function (string $default, string $reason): void {
        $column = new Column('api_key', 'varchar', 'varchar(255)', true, $default);

        expect(SecretHeuristics::reason($column))->toContain($reason);
    })->with([
        ["'sk_live_51H8xQ2eZvKYlo2C'", 'Stripe'],
        ["'ghp_16C7e42F292c6912E7710c838347Ae178B4a'", 'GitHub'],
        ["'AKIAIOSFODNN7EXAMPLE'", 'AWS'],
        ["'-----BEGIN RSA PRIVATE KEY-----'", 'private key'],
        ["'postgres://admin:hunter2@db.internal:5432/app'", 'username and password'],
        ["'d41d8cd98f00b204e9800998ecf8427ed41d8cd9'", 'random-looking'],
        ["'host=db password=hunter2'", 'credential'],
    ]);

    /**
     * The heuristics are only useful if they stay quiet. A warning on every ordinary
     * default trains people to ignore the one that matters.
     */
    it('stays quiet about ordinary defaults', function (string $default): void {
        expect(SecretHeuristics::reason(new Column('c', 'varchar', 'varchar(255)', true, $default)))
            ->toBeNull();
    })->with([
        "'draft'",
        "'pending'::character varying",
        '0',
        'CURRENT_TIMESTAMP',
        "nextval('users_id_seq'::regclass)",
        'gen_random_uuid()',
        "'en_GB'",
        "'a short sentence that is long enough to pass a length check but has spaces'",
    ]);

    it('says nothing when a column has no default at all', function (): void {
        expect(SecretHeuristics::reason(new Column('c', 'varchar', 'varchar(255)', true)))->toBeNull();
    });

    it('finds suspects across a whole schema', function (): void {
        $schema = new DatabaseSchema([
            new Table('a', [new Column('token', 'varchar', 'varchar(255)', true, "'ghp_16C7e42F292c6912E7710c838347Ae178B4a'")]),
            new Table('b', [new Column('status', 'varchar', 'varchar(255)', true, "'draft'")]),
        ]);

        $suspects = SecretHeuristics::suspects($schema);

        expect($suspects)->toHaveCount(1)
            ->and($suspects[0]['table'])->toBe('a')
            ->and($suspects[0]['column'])->toBe('token')
            ->and(SecretHeuristics::describe($suspects)[0])->toContain('a.token');
    });

    it('warns when recording a baseline that would publish one', function (): void {
        Schema::create('integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('api_key')->default('ghp_16C7e42F292c6912E7710c838347Ae178B4a');
        });

        [$exit, $output] = runCommand('difflock:diff', ['--save' => true]);

        expect($exit)->toBe(0)
            ->and($output)->toContain('may hold a credential')
            ->toContain('integrations.api_key')
            ->toContain('snapshot.defaults');
    });

    it('says nothing when recording an ordinary schema', function (): void {
        [, $output] = runCommand('difflock:diff', ['--save' => true]);

        expect($output)->not->toContain('may hold a credential');
    });
});
