<?php

declare(strict_types=1);

namespace Difflock\Console\Renderers;

/**
 * The small amount of typesetting the console output needs.
 */
final class Text
{
    /** How wide prose is wrapped. Narrow enough to read, wide enough not to look sparse. */
    public const int WIDTH = 76;

    /**
     * Wrap a paragraph to the given width, indenting every line.
     *
     * @return list<string>
     */
    public static function wrap(string $text, string $indent = '', int $width = self::WIDTH): array
    {
        $available = max($width - mb_strlen($indent), 20);

        $lines = [];

        foreach (explode("\n", wordwrap(trim($text), $available, "\n", false)) as $line) {
            $lines[] = $indent.$line;
        }

        return $lines;
    }

    /** A rule, glyph or level padded to a fixed column so the text beside it lines up. */
    public static function pad(string $value, int $width): string
    {
        return str_pad($value, $width);
    }

    public static function divider(int $width = 40): string
    {
        return str_repeat('─', $width);
    }
}
