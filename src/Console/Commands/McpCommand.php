<?php

declare(strict_types=1);

namespace Difflock\Console\Commands;

use Difflock\Checkup;
use Difflock\Contracts\MigrationAnalyzer;
use Difflock\Database\DatabaseContextFactory;
use Difflock\Mcp\Server;
use Difflock\Mcp\Tools\LintMigration;
use Difflock\Mcp\Tools\SchemaDrift;
use Difflock\Mcp\Tools\TableContext;
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

        $server = new Server([
            new TableContext($contexts),
            new LintMigration($analyzer),
            new SchemaDrift($checkup),
        ]);

        $server->serve(STDIN, STDOUT);

        return self::SUCCESS;
    }
}
