<?php

declare(strict_types=1);

namespace Difflock\Support;

/**
 * The broad family a column type belongs to, on either side of the fence.
 *
 * Comparing a blueprint's `string()` against a database's `character varying(255)`
 * by name is hopeless — every driver spells everything differently, and half of them
 * spell it two ways. Comparing *families* is a question that can be answered
 * honestly: text is not integer, and a migration turning one into the other is worth
 * a finding whatever the driver calls them.
 *
 * A type neither side recognises returns null, and the rules then say nothing about
 * the type rather than guessing. That is the point: this exists to avoid claiming a
 * type change that is really a spelling difference.
 */
final class TypeFamily
{
    /**
     * Blueprint methods, grouped.
     *
     * @var array<string, list<string>>
     */
    private const array BLUEPRINT = [
        'text' => ['char', 'string', 'text', 'tinyText', 'mediumText', 'longText', 'enum', 'set',
            'ipAddress', 'macAddress'],
        'integer' => ['integer', 'tinyInteger', 'smallInteger', 'mediumInteger', 'bigInteger',
            'unsignedInteger', 'unsignedTinyInteger', 'unsignedSmallInteger', 'unsignedMediumInteger',
            'unsignedBigInteger', 'increments', 'tinyIncrements', 'smallIncrements',
            'mediumIncrements', 'bigIncrements', 'id', 'foreignId', 'year'],
        'decimal' => ['decimal', 'unsignedDecimal', 'double', 'float'],
        'boolean' => ['boolean'],
        'date' => ['date'],
        'datetime' => ['dateTime', 'dateTimeTz', 'timestamp', 'timestampTz'],
        'time' => ['time', 'timeTz'],
        'json' => ['json', 'jsonb'],
        'binary' => ['binary'],
        'uuid' => ['uuid', 'ulid', 'foreignUuid', 'foreignUlid'],
    ];

    /**
     * Database type names, grouped. Matched by prefix, so `character varying` and
     * `varchar` land in the same place as `varchar(255)`.
     *
     * @var array<string, list<string>>
     */
    private const array DATABASE = [
        'text' => ['char', 'varchar', 'character', 'nvarchar', 'text', 'tinytext', 'mediumtext',
            'longtext', 'enum', 'set', 'citext', 'inet', 'macaddr', 'string'],
        'integer' => ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint', 'int2', 'int4',
            'int8', 'serial', 'bigserial', 'smallserial', 'year'],
        'decimal' => ['decimal', 'numeric', 'double', 'float', 'real', 'money'],
        'boolean' => ['bool', 'boolean'],
        'date' => ['date'],
        'datetime' => ['datetime', 'timestamp'],
        'time' => ['time'],
        'json' => ['json', 'jsonb'],
        'binary' => ['binary', 'varbinary', 'blob', 'bytea', 'tinyblob', 'mediumblob', 'longblob'],
        'uuid' => ['uuid'],
    ];

    /** The family a blueprint method belongs to, or null if it is not one of them. */
    public static function ofBlueprint(string $method): ?string
    {
        foreach (self::BLUEPRINT as $family => $methods) {
            if (in_array($method, $methods, true)) {
                return $family;
            }
        }

        return null;
    }

    /** The family a database type name belongs to, or null if it is not recognised. */
    public static function ofDatabase(string $type): ?string
    {
        $type = strtolower(trim($type));

        // `timestamp without time zone`, `character varying(320)`: the family is
        // decided by the leading word, and the rest is spelling.
        foreach (self::DATABASE as $family => $names) {
            foreach ($names as $name) {
                if ($type === $name || str_starts_with($type, $name.'(') || str_starts_with($type, $name.' ')) {
                    return $family;
                }
            }
        }

        return null;
    }

    /**
     * Whether the migration is changing the family of the column.
     *
     * Null — "one side or the other is a type Difflock does not recognise" — is
     * false, not true. An unrecognised type is not evidence of a change.
     */
    public static function changes(string $method, string $databaseType): bool
    {
        $to = self::ofBlueprint($method);
        $from = self::ofDatabase($databaseType);

        return $to !== null && $from !== null && $to !== $from;
    }
}
