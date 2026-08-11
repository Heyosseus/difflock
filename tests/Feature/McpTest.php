<?php

declare(strict_types=1);

use Difflock\Checkup;
use Difflock\Contracts\MigrationAnalyzer;
use Difflock\Database\DatabaseContextFactory;
use Difflock\Mcp\Server;
use Difflock\Mcp\Tools\LintMigration;
use Difflock\Mcp\Tools\SchemaDrift;
use Difflock\Mcp\Tools\TableContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function server(): Server
{
    return new Server([
        new TableContext(app(DatabaseContextFactory::class)),
        new LintMigration(app(MigrationAnalyzer::class)),
        new SchemaDrift(app(Checkup::class)),
    ]);
}

/**
 * @return array<string, mixed>|null
 */
function rpc(string $method, array $params = [], mixed $id = 1): ?array
{
    return server()->dispatch(json_encode(
        array_filter(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]),
        JSON_THROW_ON_ERROR,
    ));
}

/**
 * @return array<string, mixed>
 */
function callTool(string $name, array $arguments = []): array
{
    $response = rpc('tools/call', ['name' => $name, 'arguments' => $arguments]);

    return json_decode($response['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('legacy_token')->nullable();
        $table->index('legacy_token');
    });

    config()->set('difflock.migrations.paths', [fixtures()]);
});

describe('protocol', function (): void {
    it('announces itself on initialize', function (): void {
        $result = rpc('initialize')['result'];

        expect($result['protocolVersion'])->toBe(Server::PROTOCOL)
            ->and($result['serverInfo']['name'])->toBe('difflock')
            ->and($result['capabilities'])->toHaveKey('tools');
    });

    it('lists its tools with schemas an agent can call', function (): void {
        $tools = rpc('tools/list')['result']['tools'];

        expect(array_column($tools, 'name'))->toBe([
            'difflock_table_context',
            'difflock_lint_migration',
            'difflock_schema_drift',
        ]);

        foreach ($tools as $tool) {
            expect($tool['description'])->not->toBeEmpty()
                ->and($tool['inputSchema']['type'])->toBe('object');
        }
    });

    /**
     * A notification carries no id and must receive no reply. Answering one is a
     * protocol violation that some clients treat as fatal.
     */
    it('stays silent on a notification', function (): void {
        expect(server()->dispatch('{"jsonrpc":"2.0","method":"notifications/initialized"}'))->toBeNull();
    });

    it('reports malformed input as a protocol error rather than crashing', function (): void {
        expect(server()->dispatch('not json')['error']['code'])->toBe(-32700)
            ->and(server()->dispatch('"a string"')['error']['code'])->toBe(-32600)
            ->and(rpc('nonsense/method')['error']['code'])->toBe(-32601);
    });

    it('reports an unknown tool without pretending to answer', function (): void {
        expect(rpc('tools/call', ['name' => 'difflock_invented'])['error']['code'])->toBe(-32602);
    });

    /**
     * STDOUT carries the protocol and nothing else. A tool that throws must come back
     * as a tool result the agent can reason about, not as a broken stream.
     */
    it('turns a failing tool into a result, not a dead connection', function (): void {
        $result = rpc('tools/call', [
            'name' => 'difflock_lint_migration',
            'arguments' => ['path' => 12345],
        ])['result'];

        expect($result['content'][0]['text'])->toContain('A path is required');
    });
});

describe('difflock_table_context', function (): void {
    it('describes a real table', function (): void {
        $context = callTool('difflock_table_context', ['table' => 'users']);

        expect($context['exists'])->toBeTrue()
            ->and($context['driver'])->toBe('sqlite')
            ->and($context['rows'])->toBe(0)
            ->and(array_column($context['columns'], 'name'))->toContain('legacy_token')
            ->and($context['indexes'])->not->toBeEmpty()
            ->and($context['is_large'])->toBeFalse();
    });

    it('says a table does not exist rather than returning an empty one', function (): void {
        $context = callTool('difflock_table_context', ['table' => 'nope']);

        expect($context['exists'])->toBeFalse()
            ->and($context['known_tables'])->toContain('users');
    });
});

describe('difflock_lint_migration', function (): void {
    it('finds the destructive operation in a single migration file', function (): void {
        $result = callTool('difflock_lint_migration', [
            'path' => fixtures().'/2026_08_10_120000_remove_legacy_token.php',
        ]);

        expect($result['risk'])->toBe('critical')
            ->and($result['analysed'])->toBe(1)
            ->and($result['findings'][0]['rule'])->toBe('drop-column')
            ->and($result['findings'][0]['destructive'])->toBeTrue()
            ->and($result['findings'][0]['reversible'])->toBeFalse()
            ->and($result)->toHaveKey('warnings');
    });

    it('analyses a whole directory too', function (): void {
        expect(callTool('difflock_lint_migration', ['path' => fixtures()])['analysed'])->toBe(3);
    });

    it('says so when there is no migration at the path', function (): void {
        expect(callTool('difflock_lint_migration', ['path' => fixtures('nowhere')]))
            ->toHaveKey('error');
    });
});

describe('difflock_schema_drift', function (): void {
    it('distinguishes no drift from nobody having looked', function (): void {
        $before = callTool('difflock_schema_drift');

        expect($before['baseline_recorded'])->toBeFalse()
            ->and($before['drifted'])->toBeFalse()
            ->and($before['drift'])->toBeNull();

        runCommand('difflock:diff', ['--save' => true]);

        expect(callTool('difflock_schema_drift')['baseline_recorded'])->toBeTrue();
    });

    it('reports real drift', function (): void {
        runCommand('difflock:diff', ['--save' => true]);

        Schema::table('users', fn (Blueprint $table) => $table->string('phone')->nullable());

        $drift = callTool('difflock_schema_drift');

        expect($drift['drifted'])->toBeTrue()
            ->and($drift['drift']['changes'])->toBe(1)
            ->and($drift['passed'])->toBeFalse();
    });
});

describe('difflock:explain', function (): void {
    it('briefs on one migration with facts and no generated prose', function (): void {
        [$exit, $output] = runCommand('difflock:explain', ['migration' => 'remove_legacy_token']);

        expect($exit)->toBe(0)
            ->and($output)->toContain('# Migration briefing: 2026_08_10_120000_remove_legacy_token')
            ->toContain('Nothing below is generated')
            ->toContain('## Tables it touches')
            ->toContain('**users**')
            ->toContain('CRITICAL — drop-column')
            ->toContain('**Destructive:** yes')
            ->toContain('**Reversible:** no')
            ->toContain('Difflock reports risk, not permission');
    });

    it('emits the same facts as a document', function (): void {
        [$exit, $output] = runCommand('difflock:explain', [
            'migration' => 'remove_legacy_token',
            '--format' => 'json',
        ]);

        $document = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(0)
            ->and($document['migration'])->toBe('2026_08_10_120000_remove_legacy_token')
            ->and($document['tables']['users']['exists'])->toBeTrue()
            ->and($document['findings'][0]['rule'])->toBe('drop-column');
    });

    it('fails rather than guessing when no migration matches', function (): void {
        expect(runCommand('difflock:explain', ['migration' => 'no_such_thing'])[0])->toBe(2);
    });
});
