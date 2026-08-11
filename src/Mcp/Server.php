<?php

declare(strict_types=1);

namespace Difflock\Mcp;

use Difflock\Version;
use JsonException;
use Throwable;

/**
 * A Model Context Protocol server, speaking JSON-RPC 2.0 over stdio.
 *
 * ## Why this exists
 *
 * An agent writing a migration cannot see what Difflock can see. It does not know
 * the table has eight million rows, that two indexes are built on the column it is
 * about to drop, or that the schema already drifted from the baseline last Tuesday.
 * So it writes the migration that passes review and takes production down — the
 * exact failure this package was built to catch, now generated faster than before.
 *
 * These tools close that loop. The agent writes a migration, asks Difflock about it,
 * and gets back the same findings a reviewer would — before anybody sees the pull
 * request.
 *
 * ## Why standalone rather than a Boost plugin
 *
 * Laravel Boost ships its own MCP server, and an adapter into it would be a smaller
 * thing to build. But Boost publishes no documented API for third-party tool
 * registration, and writing against an undocumented internal is how a package breaks
 * on somebody else's patch release. A standalone stdio server works with Boost,
 * Claude Code, Cursor and anything else that speaks the protocol, and depends on
 * nothing but the protocol itself. If Boost later documents a registration point, an
 * adapter over these same tools is a few dozen lines.
 *
 * ## The one rule of stdio transport
 *
 * **STDOUT carries the protocol and nothing else.** One stray line of logging
 * corrupts the stream for every message after it, and the failure looks like the
 * agent going mute rather than like an error. Everything diagnostic goes to STDERR.
 */
final class Server
{
    /** The protocol revision this server implements. */
    public const string PROTOCOL = '2024-11-05';

    /** @var array<string, Tool> */
    private array $tools = [];

    /**
     * @param  list<Tool>  $tools
     */
    public function __construct(array $tools = [])
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /**
     * Read requests until the input closes.
     *
     * The streams are typed `mixed` because PHP has no `resource` type to declare;
     * the docblock carries what they actually are.
     *
     * @param  resource  $input
     * @param  resource  $output
     */
    public function serve(mixed $input, mixed $output): void
    {
        while (($line = fgets($input)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $response = $this->dispatch($line);

            // A notification has no id and takes no reply. Answering one is a protocol
            // violation that some clients treat as fatal.
            if ($response !== null) {
                fwrite($output, json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
                fflush($output);
            }
        }
    }

    /**
     * Run the handler with STDOUT sealed off.
     *
     * This is the difference between a server that works and one that dies silently
     * on somebody else's application. **STDOUT carries the protocol**, and anything
     * else written to it — a `dd()` left in a model, a deprecation notice from a
     * config file, a package that echoes during boot — lands in the middle of the
     * JSON-RPC stream. The client sees malformed JSON, gives up, and the failure
     * presents as the tools simply not existing. Nobody debugs that quickly.
     *
     * A real example, found on a live application: `config/database.php` referencing
     * `PDO::MYSQL_ATTR_SSL_CA` on PHP 8.5 emits a deprecation notice to STDOUT, and
     * that alone was enough to make the server mute.
     *
     * So every handler runs inside an output buffer. Whatever it prints is captured
     * and thrown away rather than corrupting the stream, and the protocol frame is
     * written afterwards by the caller. Output produced *before* this point — during
     * framework bootstrap — cannot be caught here; {@see \Difflock\Console\Commands\McpCommand}
     * handles that end.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $handler
     * @return TReturn
     */
    private function guarded(callable $handler): mixed
    {
        ob_start();

        try {
            return $handler();
        } finally {
            $stray = ob_get_clean();

            if (is_string($stray) && trim($stray) !== '') {
                // Not silently discarded: an operator debugging a quiet server needs
                // to know something is writing where it should not.
                fwrite(STDERR, 'difflock: discarded '.strlen($stray).' bytes written to STDOUT during a '
                    .'request. Something in this application prints to standard output, which corrupts '
                    ."the MCP stream.\n");
            }
        }
    }

    /**
     * Handle one line of input, returning the response to write, or null for a
     * notification.
     *
     * @return array<string, mixed>|null
     */
    public function dispatch(string $line): ?array
    {
        // The guard lives here rather than in serve() so that it protects every entry
        // point, not just the one the production transport happens to use. A caller
        // that dispatches directly deserves the same guarantee.
        return $this->guarded(fn (): ?array => $this->route($line));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function route(string $line): ?array
    {
        try {
            $message = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return $this->error(null, -32700, 'Parse error: '.$exception->getMessage());
        }

        if (! is_array($message)) {
            return $this->error(null, -32600, 'A request must be a JSON object.');
        }

        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;

        if (! is_string($method)) {
            return $this->error($id, -32600, 'A request must name a method.');
        }

        // Notifications carry no id and expect no answer.
        if ($id === null) {
            return null;
        }

        $params = $message['params'] ?? [];

        return match ($method) {
            'initialize' => $this->result($id, [
                'protocolVersion' => self::PROTOCOL,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'difflock', 'version' => Version::CURRENT],
            ]),
            'ping' => $this->result($id, []),
            'tools/list' => $this->result($id, ['tools' => $this->describe()]),
            'tools/call' => $this->call($id, $this->named($params)),
            default => $this->error($id, -32601, 'There is no method called '.$method.'.'),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function describe(): array
    {
        $described = [];

        foreach ($this->tools as $tool) {
            $described[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->schema(),
            ];
        }

        return $described;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function call(mixed $id, array $params): array
    {
        $name = $params['name'] ?? null;

        if (! is_string($name) || ! isset($this->tools[$name])) {
            return $this->error($id, -32602, 'There is no tool called '.(is_string($name) ? $name : '?').'.');
        }

        try {
            $result = $this->tools[$name]->handle($this->named($params['arguments'] ?? []));
        } catch (Throwable $exception) {
            // Reported as a tool result rather than a protocol error, because the tool
            // failing is something the agent can reason about and recover from; a
            // protocol error is something it can only give up on.
            return $this->result($id, [
                'isError' => true,
                'content' => [['type' => 'text', 'text' => 'Difflock could not answer: '.$exception->getMessage()]],
            ]);
        }

        return $this->result($id, [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]],
        ]);
    }

    /**
     * Whatever arrived from JSON, reduced to the named arguments a tool expects.
     *
     * JSON arrays decode with integer keys, so a client sending `[1, 2]` where an
     * object was expected would otherwise reach a tool as positional data it has no
     * way to interpret. Keys that are not names are dropped at the boundary, and the
     * tool contract stays honest about receiving named arguments.
     *
     * @return array<string, mixed>
     */
    private function named(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $named = [];

        foreach ($value as $key => $argument) {
            if (is_string($key)) {
                $named[$key] = $argument;
            }
        }

        return $named;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function result(mixed $id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
