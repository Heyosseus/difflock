<?php

declare(strict_types=1);

namespace Difflock\Console\Commands;

use Difflock\Checkup;
use Difflock\Contracts\MigrationAnalyzer;
use Difflock\Database\DatabaseContextFactory;
use Difflock\Mcp\Server;
use Difflock\Mcp\Tools\LintMigration;
use Difflock\Mcp\Tools\Rules;
use Difflock\Mcp\Tools\SchemaDrift;
use Difflock\Mcp\Tools\TableContext;
use Difflock\RuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

/**
 * Serves Difflock's analysis to AI agents over the Model Context Protocol.
 *
 * Not meant to be run by hand — an MCP client starts it and talks JSON-RPC over the
 * pipe. Add it to Claude Code, Cursor, or Laravel Boost's client configuration:
 *
 *     {"mcpServers": {"difflock": {
 *         "command": "php",
 *         "args": ["artisan", "difflock:mcp"]
 *     }}}
 *
 * The agent then has three tools, and the useful order is the order of a careful
 * developer: ask what the table looks like, write the migration, ask what is wrong
 * with it.
 */
final class McpCommand extends Command
{
    /**
     * Hidden because a human running it gets a process that appears to hang while it
     * waits for JSON-RPC on a pipe that is not coming.
     */
    protected $hidden = true;

    protected $signature = 'difflock:mcp';

    protected $description = 'Serve Difflock to AI agents over the Model Context Protocol (stdio)';

    public function handle(
        Repository $config,
        MigrationAnalyzer $analyzer,
        DatabaseContextFactory $contexts,
        Checkup $checkup,
    ): int {
        if ($config->get('difflock.enabled') === false) {
            // Written to STDERR: STDOUT is the protocol.
            fwrite(STDERR, "Difflock is disabled, so its tools would report nothing.\n");

            return self::INVALID;
        }

        $this->protectTheStream();

        $server = new Server([
            new TableContext($contexts),
            new LintMigration($analyzer),
            new SchemaDrift($checkup),
            new Rules($this->laravel->make(RuleRegistry::class), $this->laravel),
        ]);

        $server->serve(STDIN, STDOUT);

        return self::SUCCESS;
    }

    /**
     * Stop the host application writing into the protocol stream.
     *
     * PHP sends warnings, notices and deprecations to STDOUT when `display_errors`
     * is on, which is the default in most local setups. On this transport that is
     * fatal rather than untidy: one deprecation from a config file lands ahead of
     * the handshake, the client cannot parse it, and Difflock's tools appear not to
     * exist. There is nothing in the error to suggest the cause.
     *
     * Diagnostics are not lost — they are redirected to STDERR, where MCP clients
     * collect server logs. So the operator still sees the deprecation; the agent
     * still gets clean JSON.
     *
     * This covers everything from here on. Output emitted *earlier*, while the
     * framework booted, is already gone by the time any command runs, which is why
     * the documented invocation passes `-d display_errors=stderr` to PHP itself.
     */
    private function protectTheStream(): void
    {
        ini_set('display_errors', 'stderr');
        ini_set('log_errors', '0');

        if (ob_get_level() > 0) {
            // Something has been buffering since before this command. Flushing it to
            // STDOUT now would be the exact corruption this method exists to prevent.
            $pending = ob_get_clean();

            if (is_string($pending) && trim($pending) !== '') {
                fwrite(STDERR, "difflock: discarded output buffered before the server started.\n");
            }
        }
    }
}
