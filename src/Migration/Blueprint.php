<?php

declare(strict_types=1);

namespace Difflock\Migration;

use Difflock\Migration\Parser\Modifier;
use Difflock\Migration\Parser\Operation;

/**
 * What Laravel's blueprint methods mean, as far as the rules need to know.
 *
 * Kept in one place rather than spread across the rules, because the awkward cases
 * are shared: `softDeletes()` names a column nobody passed as an argument and is
 * nullable without anybody saying `nullable()`; `id()` is not a column you can add
 * to a populated table and then complain about; `timestamps()` is two columns at
 * once. A rule that had to remember all of that would get one of them wrong.
 *
 * The lists are exhaustive for the framework's own blueprint. A method not listed is
 * treated as "not a column", which is the direction that produces silence rather
 * than a wrong finding — and a macro somebody added to the blueprint is exactly the
 * sort of thing static analysis should decline to have opinions about.
 */
final class Blueprint
{
    /**
     * Methods that define a column.
     *
     * @var list<string>
     */
    private const array COLUMNS = [
        'bigIncrements', 'bigInteger', 'binary', 'boolean', 'char', 'computed', 'date', 'dateTime',
        'dateTimeTz', 'decimal', 'double', 'enum', 'float', 'foreignId', 'foreignIdFor', 'foreignUlid',
        'foreignUuid', 'geography', 'geometry', 'id', 'increments', 'integer', 'ipAddress', 'json',
        'jsonb', 'longText', 'macAddress', 'mediumIncrements', 'mediumInteger', 'mediumText', 'morphs',
        'nullableMorphs', 'nullableTimestamps', 'nullableUlidMorphs', 'nullableUuidMorphs',
        'rememberToken', 'set', 'smallIncrements', 'smallInteger', 'softDeletes', 'softDeletesTz',
        'string', 'text', 'time', 'timeTz', 'timestamp', 'timestampTz', 'timestamps', 'timestampsTz',
        'tinyIncrements', 'tinyInteger', 'tinyText', 'ulid', 'ulidMorphs', 'unsignedBigInteger',
        'unsignedDecimal', 'unsignedInteger', 'unsignedMediumInteger', 'unsignedSmallInteger',
        'unsignedTinyInteger', 'uuid', 'uuidMorphs', 'vector', 'year',
    ];

    /**
     * Column methods that produce something nullable, or fill themselves, without
     * anybody chaining `nullable()` or `default()` onto them.
     *
     * @var list<string>
     */
    private const array IMPLICITLY_NULLABLE = [
        'nullableMorphs', 'nullableTimestamps', 'nullableUlidMorphs', 'nullableUuidMorphs',
        'rememberToken', 'softDeletes', 'softDeletesTz', 'timestamps', 'timestampsTz',
    ];

    /**
     * Column methods that create the table's own key, which is not a column you add
     * to a populated table and then worry about defaults for.
     *
     * @var list<string>
     */
    private const array AUTO_INCREMENT = [
        'bigIncrements', 'id', 'increments', 'mediumIncrements', 'smallIncrements', 'tinyIncrements',
    ];

    /**
     * Methods that add an index.
     *
     * @var list<string>
     */
    private const array INDEXES = ['index', 'unique', 'primary', 'fullText', 'spatialIndex', 'rawIndex'];

    /**
     * Methods that remove an index.
     *
     * @var list<string>
     */
    private const array DROP_INDEXES = [
        'dropIndex', 'dropUnique', 'dropPrimary', 'dropFullText', 'dropSpatialIndex',
    ];

    /**
     * Methods that remove one or more columns.
     *
     * @var list<string>
     */
    private const array DROP_COLUMNS = [
        'dropColumn', 'dropSoftDeletes', 'dropSoftDeletesTz', 'dropRememberToken', 'dropTimestamps',
        'dropTimestampsTz', 'dropMorphs', 'dropUlidMorphs', 'dropUuidMorphs',
        'dropConstrainedForeignId', 'dropConstrainedForeignIdFor',
    ];

    /**
     * The columns a drop method removes when it names none: `dropSoftDeletes()` takes
     * `deleted_at` and says nothing about it.
     *
     * @var array<string, list<string>>
     */
    private const array IMPLIED_DROPS = [
        'dropSoftDeletes' => ['deleted_at'],
        'dropSoftDeletesTz' => ['deleted_at'],
        'dropRememberToken' => ['remember_token'],
        'dropTimestamps' => ['created_at', 'updated_at'],
        'dropTimestampsTz' => ['created_at', 'updated_at'],
    ];

    /**
     * The columns a definition method creates when it names none.
     *
     * @var array<string, list<string>>
     */
    private const array IMPLIED_COLUMNS = [
        'id' => ['id'],
        'increments' => ['id'],
        'bigIncrements' => ['id'],
        'rememberToken' => ['remember_token'],
        'softDeletes' => ['deleted_at'],
        'softDeletesTz' => ['deleted_at'],
        'timestamps' => ['created_at', 'updated_at'],
        'timestampsTz' => ['created_at', 'updated_at'],
        'nullableTimestamps' => ['created_at', 'updated_at'],
        'ulid' => ['ulid'],
        'uuid' => ['uuid'],
    ];

    /**
     * Modifiers that give a new column something to put in the rows that already
     * exist, so adding it to a populated table is not an error waiting to happen.
     *
     * @var list<string>
     */
    private const array FILLS_EXISTING_ROWS = [
        'default', 'nullable', 'useCurrent', 'useCurrentOnUpdate', 'storedAs', 'virtualAs',
        'autoIncrement', 'generatedAs', 'always',
    ];

    public static function isColumn(string $method): bool
    {
        return in_array($method, self::COLUMNS, true);
    }

    public static function isIndex(string $method): bool
    {
        return in_array($method, self::INDEXES, true);
    }

    public static function isDropIndex(string $method): bool
    {
        return in_array($method, self::DROP_INDEXES, true);
    }

    public static function isDropColumn(string $method): bool
    {
        return in_array($method, self::DROP_COLUMNS, true);
    }

    public static function isAutoIncrement(string $method): bool
    {
        return in_array($method, self::AUTO_INCREMENT, true);
    }

    /**
     * Whether the new column this operation defines would leave existing rows with no
     * value — the thing that makes an `ALTER TABLE` fail halfway through a deploy.
     *
     * A `nullable(false)` is an explicit *not* null and is read as one; anything else
     * chained as `nullable()` means the column accepts nothing and is fine.
     */
    public static function requiresValueForExistingRows(Operation $operation): bool
    {
        if (! self::isColumn($operation->method)) {
            return false;
        }

        if (self::isAutoIncrement($operation->method)) {
            return false;
        }

        if (in_array($operation->method, self::IMPLICITLY_NULLABLE, true)) {
            return false;
        }

        foreach ($operation->modifiers as $modifier) {
            if (self::fills($modifier)) {
                return false;
            }
        }

        return true;
    }

    /** Whether the column, as declared, accepts null. */
    public static function isNullable(Operation $operation): bool
    {
        if (in_array($operation->method, self::IMPLICITLY_NULLABLE, true)) {
            return true;
        }

        $nullable = $operation->modifier('nullable');

        if (! $nullable instanceof Modifier) {
            return false;
        }

        // `nullable(false)` is how a `change()` makes a column NOT NULL.
        return $nullable->argument(0) !== false;
    }

    /**
     * The columns this operation names, falling back to the ones the method implies.
     *
     * @return list<string>
     */
    public static function columnsOf(Operation $operation): array
    {
        $named = $operation->allColumns();

        if ($named !== []) {
            return $named;
        }

        return self::IMPLIED_DROPS[$operation->method]
            ?? self::IMPLIED_COLUMNS[$operation->method]
            ?? [];
    }

    private static function fills(Modifier $modifier): bool
    {
        if ($modifier->method === 'nullable') {
            return $modifier->argument(0) !== false;
        }

        return in_array($modifier->method, self::FILLS_EXISTING_ROWS, true);
    }
}
