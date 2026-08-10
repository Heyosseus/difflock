<?php

declare(strict_types=1);

namespace Difflock\Console\Renderers;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * The heading every human-readable command opens with.
 *
 * Rendered with style tags rather than raw escape codes, so `--no-ansi` strips them
 * and the output stays readable rather than turning into punctuation.
 */
final class Banner
{
    public static function render(OutputInterface $output, string $title = 'Difflock'): void
    {
        $output->writeln('');
        $output->writeln('  <options=bold>'.$title.'</>');
        $output->writeln('  <fg=gray>'.Text::divider().'</>');
        $output->writeln('');
    }
}
