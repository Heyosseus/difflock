<?php

declare(strict_types=1);

namespace Difflock\Migration\Parser;

use PhpToken;

/**
 * Reads a migration file's `up()` method with PHP's own tokenizer and reports the
 * schema operations it can see.
 *
 * ## What this is, and what it is not
 *
 * This is **static analysis**, and Laravel migrations are arbitrary executable PHP.
 * A migration may branch on config, loop over a list built at runtime, or drop to
 * `DB::statement()` — and no static reader can resolve any of those. Difflock's
 * answer is not to guess. It reads what it can read, and it says out loud what it
 * could not:
 *
 *   - a table or column name that is not a literal becomes {@see Unresolved}, and
 *     the finding built from it says so instead of naming a column;
 *   - an operation inside an `if`, a loop or a `try` is marked conditional, and the
 *     rules downgrade nothing but describe it as *may* rather than *will*;
 *   - a raw `DB::statement()` produces a warning saying part of the file was not
 *     analysed at all.
 *
 * Nothing in this class executes the migration or loads its class. A linter that
 * boots the code it is linting is a linter that can be made to drop your tables.
 *
 * The parser is deliberately not a PHP parser. It recognises exactly the two shapes
 * migrations are written in — `Schema::method(...)` and `$table->method(...)->...` —
 * and treats everything else as something it did not understand, which is the safe
 * direction for it to be wrong in.
 */
final class MigrationParser
{
    /**
     * The `Schema::` methods that describe a change to the schema. Everything else
     * on the facade — `hasTable`, `getColumnListing`, `disableForeignKeyConstraints`
     * — reads or configures rather than changes, and is not a subject for a rule.
     *
     * @var list<string>
     */
    private const array SCHEMA_METHODS = [
        'create', 'createIfNotExists', 'table', 'drop', 'dropIfExists', 'rename',
        'dropColumns', 'dropAllTables',
    ];

    /**
     * Facade methods that hand SQL straight to the driver, which this parser cannot
     * read and does not pretend to.
     *
     * @var list<string>
     */
    private const array RAW_METHODS = ['statement', 'unprepared', 'raw'];

    /** @var list<PhpToken> */
    private array $tokens = [];

    /** @var list<string> */
    private array $warnings = [];

    public function parse(string $source, string $name, string $path = ''): ParsedMigration
    {
        $this->tokens = $this->tokenize($source);
        $this->warnings = [];

        $up = $this->methodBody('up');

        if ($up === null) {
            $this->warn('No up() method was found, so nothing in this file was analysed.');

            return new ParsedMigration($name, $path, [], false, $this->warnings);
        }

        $statements = $this->statements($up[0], $up[1]);
        $down = $this->methodBody('down');

        return new ParsedMigration(
            name: $name,
            path: $path,
            statements: $statements,
            reversible: $down !== null && $down[1] >= $down[0],
            warnings: array_values(array_unique($this->warnings)),
        );
    }

    /**
     * @return list<PhpToken>
     */
    private function tokenize(string $source): array
    {
        $tokens = [];

        foreach (PhpToken::tokenize($source) as $token) {
            if (! $token->isIgnorable()) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * The token range inside a named method's braces, or null if it has no body.
     *
     * @return array{0: int, 1: int}|null
     */
    private function methodBody(string $name): ?array
    {
        foreach ($this->tokens as $index => $token) {
            if ($token->id !== T_FUNCTION) {
                continue;
            }

            $named = $this->tokens[$index + 1] ?? null;
            if ($named === null) {
                continue;
            }
            if ($named->id !== T_STRING) {
                continue;
            }
            if ($named->text !== $name) {
                continue;
            }

            $open = $this->tokens[$index + 2] ?? null;
            if ($open === null) {
                continue;
            }
            if ($open->text !== '(') {
                continue;
            }

            $closeParen = $this->matching($index + 2, '(', ')');

            if ($closeParen === null) {
                return null;
            }

            $brace = $this->seek('{', $closeParen + 1);

            if ($brace === null) {
                return null;
            }

            $closeBrace = $this->matching($brace, '{', '}');

            return $closeBrace === null ? null : [$brace + 1, $closeBrace - 1];
        }

        return null;
    }

    /**
     * Walk a method body, collecting every `Schema::` call and noting which of them
     * sit somewhere that may not run.
     *
     * @return list<SchemaStatement>
     */
    private function statements(int $start, int $end): array
    {
        $statements = [];
        $blocks = new BlockTracker;

        for ($index = $start; $index <= $end; $index++) {
            if ($blocks->consume($this->tokens[$index])) {
                continue;
            }

            if ($this->isFacade($index, 'DB') && $this->isRawCall($index)) {
                $this->warn(
                    'A raw DB statement was found. Difflock does not read SQL strings, '
                        .'so whatever it does was not analysed.',
                );

                $index = $this->endOfStatement($index);

                continue;
            }

            if (! $this->isFacade($index, 'Schema')) {
                continue;
            }

            $statement = $this->schemaStatement($index, $blocks->conditional());

            if ($statement instanceof SchemaStatement) {
                $statements[] = $statement;
            }

            $index = $this->endOfStatement($index);
        }

        return $statements;
    }

    /**
     * Read one `Schema::` call, following any chain in front of the method that
     * matters — `Schema::connection('reporting')->table(...)`.
     */
    private function schemaStatement(int $index, bool $conditional): ?SchemaStatement
    {
        $line = $this->tokens[$index]->line;
        $connection = null;
        $cursor = $index + 2;

        while (true) {
            $named = $this->tokens[$cursor] ?? null;

            if ($named === null || $named->id !== T_STRING) {
                return null;
            }

            $open = $this->tokens[$cursor + 1] ?? null;

            if ($open === null || $open->text !== '(') {
                return null;
            }

            $close = $this->matching($cursor + 1, '(', ')');

            if ($close === null) {
                return null;
            }

            $method = $named->text;
            $arguments = $this->argumentRanges($cursor + 2, $close - 1);

            if (in_array($method, self::SCHEMA_METHODS, true)) {
                return $this->build($method, $arguments, $line, $conditional, $connection);
            }

            if ($method === 'connection') {
                $value = $this->value($arguments[0] ?? null);
                $connection = is_string($value) ? $value : null;
            }

            $arrow = $this->tokens[$close + 1] ?? null;

            if ($arrow === null || ($arrow->id !== T_OBJECT_OPERATOR && $arrow->id !== T_NULLSAFE_OBJECT_OPERATOR)) {
                return null;
            }

            $cursor = $close + 2;
        }
    }

    /**
     * @param  list<array{0: int, 1: int}>  $arguments
     */
    private function build(
        string $method,
        array $arguments,
        int $line,
        bool $conditional,
        ?string $connection,
    ): SchemaStatement {
        $table = $this->value($arguments[0] ?? null);
        $name = is_string($table) ? $table : null;

        if ($name === null && $method !== 'dropAllTables') {
            $this->warn(
                'A Schema::'.$method.'() call names its table with an expression rather than a literal, '
                    .'so the table it affects could not be determined.',
            );
        }

        $operations = match ($method) {
            'create', 'createIfNotExists', 'table' => $this->closureOperations($arguments[1] ?? null, $conditional),
            'rename' => $this->renameOperation($arguments, $line, $conditional),
            'dropColumns' => $this->dropColumnsOperation($arguments, $line, $conditional),
            default => [],
        };

        return new SchemaStatement($method, $name, $operations, $line, $conditional, $connection);
    }

    /**
     * @param  list<array{0: int, 1: int}>  $arguments
     * @return list<Operation>
     */
    private function renameOperation(array $arguments, int $line, bool $conditional): array
    {
        return [new Operation(
            'renameTable',
            [$this->value($arguments[0] ?? null), $this->value($arguments[1] ?? null)],
            [],
            $line,
            $conditional,
        )];
    }

    /**
     * `Schema::dropColumns('users', ['a', 'b'])` is the same act as a `dropColumn()`
     * inside a closure, so it is normalised into one and meets the same rule.
     *
     * @param  list<array{0: int, 1: int}>  $arguments
     * @return list<Operation>
     */
    private function dropColumnsOperation(array $arguments, int $line, bool $conditional): array
    {
        return [new Operation(
            'dropColumn',
            [$this->value($arguments[1] ?? null)],
            [],
            $line,
            $conditional,
        )];
    }

    /**
     * The operations inside the closure handed to `Schema::create()` or
     * `Schema::table()`.
     *
     * @param  array{0: int, 1: int}|null  $argument
     * @return list<Operation>
     */
    private function closureOperations(?array $argument, bool $conditional): array
    {
        if ($argument === null) {
            return [];
        }

        $closure = $this->closure($argument[0], $argument[1]);

        if ($closure === null) {
            $this->warn(
                'A schema closure was written in a form Difflock could not read, '
                    .'so the operations inside it were not analysed.',
            );

            return [];
        }

        [$start, $end, $variable] = $closure;

        return $this->operations($start, $end, $variable, $conditional);
    }

    /**
     * The body range and blueprint variable of a closure or arrow function.
     *
     * @return array{0: int, 1: int, 2: string|null}|null
     */
    private function closure(int $start, int $end): ?array
    {
        if (($this->tokens[$start] ?? null)?->id === T_STATIC) {
            $start++;
        }

        $token = $this->tokens[$start] ?? null;

        if ($token === null) {
            return null;
        }

        if ($token->id !== T_FUNCTION && $token->id !== T_FN) {
            return null;
        }

        $open = $this->seek('(', $start + 1);

        if ($open === null) {
            return null;
        }

        $close = $this->matching($open, '(', ')');

        if ($close === null) {
            return null;
        }

        $variable = $this->firstVariable($open + 1, $close - 1);

        if ($token->id === T_FN) {
            // An arrow function's body is everything after the `=>`, with no braces
            // of its own: `fn (Blueprint $table) => $table->dropColumn('token')`.
            $arrow = $this->seek('=>', $close + 1, T_DOUBLE_ARROW);

            return $arrow === null || $arrow >= $end ? null : [$arrow + 1, $end, $variable];
        }

        // `use (...)` may sit between the parameters and the body; it carries no
        // braces, so the next `{` is the body either way.
        $brace = $this->seek('{', $close + 1);

        if ($brace === null) {
            return null;
        }

        $closeBrace = $this->matching($brace, '{', '}');

        return $closeBrace === null ? null : [$brace + 1, $closeBrace - 1, $variable];
    }

    private function firstVariable(int $start, int $end): ?string
    {
        for ($index = $start; $index <= $end; $index++) {
            if ($this->tokens[$index]->id === T_VARIABLE) {
                return $this->tokens[$index]->text;
            }
        }

        return null;
    }

    /**
     * Walk a schema closure, collecting the blueprint chains inside it.
     *
     * @param  string|null  $variable  The closure's blueprint parameter. Chains on anything
     *                                 else — `$this->helper()` — are not blueprint operations.
     * @return list<Operation>
     */
    private function operations(int $start, int $end, ?string $variable, bool $conditional): array
    {
        $operations = [];
        $blocks = new BlockTracker;

        for ($index = $start; $index <= $end; $index++) {
            $token = $this->tokens[$index];

            if ($blocks->consume($token)) {
                continue;
            }

            if ($token->id !== T_VARIABLE) {
                continue;
            }

            if ($variable !== null ? $token->text !== $variable : $token->text === '$this') {
                continue;
            }

            $operation = $this->operation($index, $conditional || $blocks->conditional());

            if ($operation instanceof Operation) {
                $operations[] = $operation;

                if ($operation->conditional) {
                    $this->warn(
                        'Some operations sit inside a conditional or a loop, so whether they run '
                            .'depends on state Difflock cannot see.',
                    );
                }
            }

            $index = $this->endOfStatement($index);
        }

        return $operations;
    }

    /**
     * One `$table->method(...)->modifier(...)` chain.
     */
    private function operation(int $index, bool $conditional): ?Operation
    {
        $line = $this->tokens[$index]->line;
        $cursor = $index + 1;
        $calls = [];

        while (true) {
            $arrow = $this->tokens[$cursor] ?? null;

            if ($arrow === null || ($arrow->id !== T_OBJECT_OPERATOR && $arrow->id !== T_NULLSAFE_OBJECT_OPERATOR)) {
                break;
            }

            $named = $this->tokens[$cursor + 1] ?? null;

            if ($named === null || $named->id !== T_STRING) {
                break;
            }

            $open = $this->tokens[$cursor + 2] ?? null;

            if ($open === null || $open->text !== '(') {
                break;
            }

            $close = $this->matching($cursor + 2, '(', ')');

            if ($close === null) {
                break;
            }

            $calls[] = [$named->text, $this->values($cursor + 3, $close - 1)];
            $cursor = $close + 1;
        }

        if ($calls === []) {
            return null;
        }

        $first = array_shift($calls);

        return new Operation(
            $first[0],
            $first[1],
            array_map(static fn (array $call): Modifier => new Modifier($call[0], $call[1]), $calls),
            $line,
            $conditional,
        );
    }

    /**
     * @return list<mixed>
     */
    private function values(int $start, int $end): array
    {
        return array_map(
            $this->value(...),
            $this->argumentRanges($start, $end),
        );
    }

    /**
     * Split an argument list into one token range per argument, at the commas that
     * belong to this call rather than to something nested inside it.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function argumentRanges(int $start, int $end): array
    {
        if ($start > $end) {
            return [];
        }

        $ranges = [];
        $depth = 0;
        $from = $start;

        for ($index = $start; $index <= $end; $index++) {
            $text = $this->tokens[$index]->text;

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($text === ',' && $depth === 0) {
                $ranges[] = [$from, $index - 1];
                $from = $index + 1;
            }
        }

        if ($from <= $end) {
            $ranges[] = [$from, $end];
        }

        return $ranges;
    }

    /**
     * Reduce one argument to a PHP value, or to {@see Unresolved} if it is anything
     * this parser is not prepared to claim it understands.
     *
     * @param  array{0: int, 1: int}|null  $range
     */
    private function value(?array $range): mixed
    {
        if ($range === null) {
            return null;
        }

        [$start, $end] = $range;

        if ($start > $end) {
            return null;
        }

        // A named argument — `nullable(value: true)` — is the value with a label.
        if ($this->tokens[$start]->id === T_STRING && ($this->tokens[$start + 1] ?? null)?->text === ':') {
            $start += 2;
        }

        // A leading minus in front of a number is part of the number.
        $negative = $this->tokens[$start]->text === '-';

        if ($negative) {
            $start++;
        }

        if ($start > $end) {
            return new Unresolved($this->source($range[0], $range[1]));
        }

        if ($start === $end) {
            $token = $this->tokens[$start];

            // `null` is a literal like any other, but it cannot come back through
            // scalar(), whose null means "this token is not a literal at all".
            if (! $negative && $token->id === T_STRING && strtolower($token->text) === 'null') {
                return null;
            }

            $scalar = $this->scalar($token);

            if (is_int($scalar) || is_float($scalar)) {
                return $negative ? -$scalar : $scalar;
            }

            if ($scalar !== null && ! $negative) {
                return $scalar;
            }
        }

        $array = $this->array($start, $end);

        if ($array !== null) {
            return $array;
        }

        return new Unresolved($this->source($range[0], $range[1]));
    }

    /**
     * A single literal token's value, or null when the token is not a literal.
     */
    private function scalar(PhpToken $token): string|int|float|bool|null
    {
        return match (true) {
            $token->id === T_CONSTANT_ENCAPSED_STRING => $this->string($token->text),
            $token->id === T_LNUMBER => (int) str_replace('_', '', $token->text),
            $token->id === T_DNUMBER => (float) str_replace('_', '', $token->text),
            $token->id === T_STRING && strtolower($token->text) === 'true' => true,
            $token->id === T_STRING && strtolower($token->text) === 'false' => false,
            default => null,
        };
    }

    /**
     * A `[...]` or `array(...)` of literals, or null when this is not one.
     *
     * An element that is not a literal becomes {@see Unresolved} in place rather than
     * failing the whole array: `['status', $column]` is still known to touch `status`.
     *
     * @return list<mixed>|null
     */
    private function array(int $start, int $end): ?array
    {
        if ($this->tokens[$start]->text === '[') {
            $close = $this->matching($start, '[', ']');
        } elseif ($this->tokens[$start]->id === T_ARRAY && ($this->tokens[$start + 1] ?? null)?->text === '(') {
            $close = $this->matching($start + 1, '(', ')');
            $start++;
        } else {
            return null;
        }

        if ($close !== $end) {
            return null;
        }

        return $this->values($start + 1, $close - 1);
    }

    /** The value of a quoted literal, with its quotes and escapes resolved. */
    private function string(string $raw): string
    {
        $inner = substr($raw, 1, -1);

        if ($raw[0] === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
        }

        return stripcslashes($inner);
    }

    /** The source text of a token range, for showing a reader what was not understood. */
    private function source(int $start, int $end): string
    {
        $text = '';

        for ($index = $start; $index <= $end; $index++) {
            $text .= $this->tokens[$index]->text;
        }

        return mb_strimwidth(trim($text), 0, 60, '…');
    }

    /** Whether the token at this index is the named facade, however it is imported. */
    private function isFacade(int $index, string $facade): bool
    {
        $token = $this->tokens[$index];

        if (! in_array($token->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }

        $segments = explode('\\', $token->text);

        if (end($segments) !== $facade) {
            return false;
        }

        return ($this->tokens[$index + 1] ?? null)?->id === T_DOUBLE_COLON;
    }

    private function isRawCall(int $index): bool
    {
        $method = $this->tokens[$index + 2] ?? null;

        return $method !== null
            && $method->id === T_STRING
            && in_array($method->text, self::RAW_METHODS, true);
    }

    /** The index of the `;` that ends the statement starting here. */
    private function endOfStatement(int $index): int
    {
        $depth = 0;
        $count = count($this->tokens);

        for ($cursor = $index; $cursor < $count; $cursor++) {
            $text = $this->tokens[$cursor]->text;

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($text === ';' && $depth <= 0) {
                return $cursor;
            }
        }

        return $count - 1;
    }

    /** The index of the next token with this text, at any depth. */
    private function seek(string $text, int $from, ?int $id = null): ?int
    {
        $count = count($this->tokens);

        for ($index = $from; $index < $count; $index++) {
            $token = $this->tokens[$index];

            if ($token->text === $text && ($id === null || $token->id === $id)) {
                return $index;
            }
        }

        return null;
    }

    /** The index of the delimiter closing the one that opens at `$index`. */
    private function matching(int $index, string $open, string $close): ?int
    {
        $depth = 0;
        $count = count($this->tokens);

        for ($cursor = $index; $cursor < $count; $cursor++) {
            $text = $this->tokens[$cursor]->text;

            if ($text === $open) {
                $depth++;
            } elseif ($text === $close) {
                $depth--;

                if ($depth === 0) {
                    return $cursor;
                }
            }
        }

        return null;
    }

    private function warn(string $warning): void
    {
        $this->warnings[] = $warning;
    }
}
