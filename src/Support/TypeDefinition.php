<?php

declare(strict_types=1);

namespace Difflock\Support;

/**
 * The numbers inside a column's type string, and whether the driver has a concept of
 * unsigned at all.
 *
 * This is the one place in Difflock that reads a driver's own type syntax, and it is
 * deliberately conservative: anything it cannot recognise comes back null, which the
 * comparison layer reads as "not comparable" rather than as a difference. A schema is
 * never reported as changed because this function did not understand a type.
 */
final class TypeDefinition
{
    /**
     * Types whose parenthesised numbers are a precision and a scale rather than a
     * length. `decimal(8, 2)` is eight digits with two after the point; `varchar(8)`
     * is eight characters, and the two must not be read the same way.
     *
     * @var list<string>
     */
    private const array NUMERIC = ['decimal', 'numeric', 'float', 'double', 'real', 'money'];

    /**
     * Whether the column is unsigned, or null where the driver has no such concept.
     *
     * PostgreSQL and SQLite have no unsigned integers. Returning `false` for them
     * would be a claim — "this column is signed" — resting on nothing the driver
     * said, and would make a MySQL-to-PostgreSQL comparison report every integer as
     * changed. Null says the question does not apply.
     */
    public static function unsigned(string $definition, string $driver): ?bool
    {
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return null;
        }

        return str_contains(strtolower($definition), 'unsigned');
    }

    /** The length of a `varchar(255)`, or null for a type that has no length. */
    public static function length(string $type, string $definition): ?int
    {
        if (self::isNumeric($type)) {
            return null;
        }

        $numbers = self::numbers($definition);

        return count($numbers) === 1 ? $numbers[0] : null;
    }

    /** The precision of a `decimal(8, 2)`, or null for a type that has none. */
    public static function precision(string $type, string $definition): ?int
    {
        if (! self::isNumeric($type)) {
            return null;
        }

        return self::numbers($definition)[0] ?? null;
    }

    /** The scale of a `decimal(8, 2)`, or null for a type that has none. */
    public static function scale(string $type, string $definition): ?int
    {
        if (! self::isNumeric($type)) {
            return null;
        }

        return self::numbers($definition)[1] ?? null;
    }

    private static function isNumeric(string $type): bool
    {
        $type = strtolower(trim($type));

        foreach (self::NUMERIC as $numeric) {
            if (str_starts_with($type, $numeric)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The numbers between the first pair of parentheses, in order.
     *
     * Only the first pair: MySQL writes `enum('a','b')` and PostgreSQL writes
     * `timestamp(0) without time zone`, and reading numbers from anywhere else in the
     * string would find things that are not lengths.
     *
     * @return list<int>
     */
    private static function numbers(string $definition): array
    {
        if (preg_match('/\(([^)]*)\)/', $definition, $matches) !== 1) {
            return [];
        }

        $numbers = [];

        foreach (explode(',', $matches[1]) as $part) {
            $part = trim($part);

            if ($part === '' || preg_match('/^\d+$/', $part) !== 1) {
                // A non-numeric argument means this is not a length at all —
                // `enum('a', 'b')`, `varchar(255) collate x` — so nothing here is one.
                return [];
            }

            $numbers[] = (int) $part;
        }

        return $numbers;
    }
}
