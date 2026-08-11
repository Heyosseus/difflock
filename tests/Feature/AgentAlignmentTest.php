<?php

declare(strict_types=1);

use Difflock\Contracts\MigrationAnalyzer;
use Difflock\Mcp\Server;
use Difflock\Mcp\Tool;
use Difflock\Mcp\Tools\LintMigration;
use Difflock\Mcp\Tools\Rules;
use Difflock\RuleRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('orders', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('customer_id');
    });

    config()->set('difflock.migrations.paths', [fixtures()]);
});

/**
 * @return array<string, mixed>
 */
function lintDraft(string $source): array
{
    return (new LintMigration(app(MigrationAnalyzer::class)))->handle(['source' => $source]);
}

function draftSource(string $body): string
{
    return <<<PHP
    <?php

    use Illuminate\\Database\\Migrations\\Migration;
    use Illuminate\\Database\\Schema\\Blueprint;
    use Illuminate\\Support\\Facades\\Schema;

    return new class extends Migration
    {
        public function up(): void
        {
    {$body}
        }
    };
    PHP;
}

describe('checking a draft before it is written', function (): void {
    /**
     * The workflow this exists for: an agent validates the migration it is holding,
     * fixes it, and writes once — instead of writing a destructive migration into the
     * user's repository in order to discover that it is destructive.
     */
    it('analyses source that is not on disk', function (): void {
        $result = lintDraft(draftSource(
            "        Schema::table('orders', fn (Blueprint \$t) => \$t->dropColumn('customer_id'));",
        ));

        expect($result['analysed_from'])->toBe('source')
            ->and($result['risk'])->toBe('critical')
            ->and($result['findings'][0]['rule'])->toBe('drop-column')
            ->and($result['findings'][0]['column'])->toBe('customer_id');
    });

    it('judges a draft against the real database, not a guess', function (): void {
        DB::table('orders')->insert(array_map(
            static fn (int $i): array => ['customer_id' => $i],
            range(1, 5),
        ));

        $result = lintDraft(draftSource(
            "        Schema::table('orders', fn (Blueprint \$t) => \$t->string('status'));",
        ));

        // High, not medium: the rule saw actual rows in the actual table.
        expect($result['findings'][0]['rule'])->toBe('add-not-null-column')
            ->and($result['findings'][0]['risk'])->toBe('high')
            ->and($result['findings'][0]['context'])->toContain('5 rows');
    });

    it('leaves nothing behind on disk', function (): void {
        $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'difflock-draft-*') ?: [];

        lintDraft(draftSource("        Schema::drop('orders');"));

        expect(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'difflock-draft-*') ?: [])->toBe($before);
    });

    it('says what to do when the source is not a migration', function (): void {
        $result = lintDraft('<?php echo "not a migration";');

        expect($result['error'])->toContain('No schema operations were found')
            ->and($result['next'])->toContain('opening <?php tag');
    });

    it('says what to do when neither source nor path is given', function (): void {
        $result = (new LintMigration(app(MigrationAnalyzer::class)))->handle([]);

        expect($result['error'])->toContain('either "source"')
            ->and($result['next'])->toContain('drafting');
    });
});

describe('bounded responses', function (): void {
    /**
     * An agent that receives four hundred findings has spent its context on a wall of
     * text it cannot act on. The count stays exact; only the list is capped.
     */
    it('caps the findings it returns but never the count', function (): void {
        $result = (new LintMigration(app(MigrationAnalyzer::class)))->handle(['path' => fixtures()]);

        expect($result['showing'])->toBeLessThanOrEqual(25)
            ->and($result['total_findings'])->toBeGreaterThanOrEqual($result['showing'])
            ->and($result)->toHaveKey('truncated')
            ->and($result['counts'])->toHaveKeys(['safe', 'low', 'medium', 'high', 'critical']);
    });
});

describe('difflock_rules', function (): void {
    it('publishes what each rule checks, from the rule itself', function (): void {
        $result = (new Rules(app(RuleRegistry::class), app()))->handle([]);

        $identifiers = array_column($result['rules'], 'rule');

        expect($identifiers)->toContain('drop-column', 'unindexed-foreign-key', 'sensitive-column');

        $dropColumn = collect($result['rules'])->firstWhere('rule', 'drop-column');

        expect($dropColumn['built_in'])->toBeTrue()
            ->and($dropColumn['explains'])->toContain('dropColumn')
            ->and($dropColumn['explains'])->toContain('down()')
            ->and($dropColumn['explains'])->not->toContain('@param')
            ->and($dropColumn['explains'])->not->toContain('/**');
    });

    it('answers about one rule', function (): void {
        $result = (new Rules(app(RuleRegistry::class), app()))->handle(['rule' => 'unindexed-foreign-key']);

        expect($result['rules'])->toHaveCount(1)
            ->and($result['rules'][0]['explains'])->toContain('PostgreSQL');
    });

    it('says so rather than inventing a rule that does not exist', function (): void {
        $result = (new Rules(app(RuleRegistry::class), app()))->handle(['rule' => 'invented-rule']);

        expect($result['error'])->toContain('invented-rule')
            ->and($result['next'])->toContain('no arguments');
    });
});

describe('protocol integrity', function (): void {
    /**
     * The failure that motivated this: a live application emitted a PHP deprecation
     * to STDOUT from `config/database.php`, which landed ahead of the handshake and
     * made the whole server appear not to exist. A tool that prints must not be able
     * to do that.
     */
    it('survives a tool that writes to stdout', function (): void {
        $noisy = new class implements Tool
        {
            public function name(): string
            {
                return 'noisy';
            }

            public function description(): string
            {
                return 'Writes where it should not.';
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => [], 'required' => []];
            }

            public function handle(array $arguments): array
            {
                echo "this would corrupt the stream\n";
                print_r(['and', 'so', 'would', 'this']);

                return ['ok' => true];
            }
        };

        $server = new Server([$noisy]);

        ob_start();
        $response = $server->dispatch('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"noisy"}}');
        $leaked = ob_get_clean();

        expect($leaked)->toBe('')
            ->and($response['result']['content'][0]['text'])->toContain('"ok": true');
    });

    it('keeps the buffer balanced when a tool throws', function (): void {
        $throwing = new class implements Tool
        {
            public function name(): string
            {
                return 'throwing';
            }

            public function description(): string
            {
                return 'Fails.';
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => [], 'required' => []];
            }

            public function handle(array $arguments): array
            {
                echo 'noise before the failure';

                throw new RuntimeException('deliberate');
            }
        };

        $level = ob_get_level();

        $response = (new Server([$throwing]))
            ->dispatch('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"throwing"}}');

        expect(ob_get_level())->toBe($level)
            ->and($response['result']['isError'])->toBeTrue()
            ->and($response['result']['content'][0]['text'])->toContain('deliberate');
    });
});
