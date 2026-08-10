<?php

declare(strict_types=1);

namespace Difflock\Support;

/**
 * Byte counts, rendered the way a person reads them.
 */
final class Bytes
{
    /** @var list<string> */
    private const array UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    public static function human(int $bytes): string
    {
        $value = (float) max($bytes, 0);
        $unit = 0;

        while ($value >= 1024 && $unit < count(self::UNITS) - 1) {
            $value /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) (int) $value : number_format($value, $value < 10 ? 1 : 0))
            .' '.self::UNITS[$unit];
    }
}
