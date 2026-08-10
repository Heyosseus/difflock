<?php

declare(strict_types=1);

namespace Difflock\Console\Renderers;

use Difflock\Diff\ChangeType;
use Difflock\Diff\ColumnDiff;
use Difflock\Diff\ForeignKeyDiff;
use Difflock\Diff\IndexDiff;
use Difflock\Diff\SchemaDiff;
use Difflock\Diff\TableDiff;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints a schema diff the way `git diff` prints a patch: `+` gained, `-` lost, `~`
 * altered, and a modified thing shown as what it was above what it becomes.
 *
 * The markers carry the meaning and the colour only reinforces it, so the output is
 * exactly as readable under `--no-ansi`, in a CI log, or pasted into a pull request.
 */
final class DiffRenderer
{
    public function render(OutputInterface $output, SchemaDiff $diff): void
    {
        if ($diff->isEmpty()) {
            $output->writeln('  <fg=green>✓</> No differences.');
            $output->writeln('');

            return;
        }

        foreach ($diff->tables as $table) {
            $this->table($output, $table);
        }

        $count = $diff->count();

        $output->writeln('  '.$count.' change'.($count === 1 ? '' : 's').' detected.');
        $output->writeln('');
    }

    private function table(OutputInterface $output, TableDiff $table): void
    {
        if ($table->type !== ChangeType::Changed) {
            $output->writeln(
                '  '.$this->marker($table->type).' <options=bold>'.$table->name.'</>'
                    .' <fg=gray>table '.$table->type->value.'</>',
            );
            $output->writeln('');

            return;
        }

        $output->writeln('  <options=bold>'.$table->name.'</>');

        foreach ($table->columns as $column) {
            $this->column($output, $column);
        }

        if ($table->indexes !== []) {
            $output->writeln('    <fg=gray>Indexes</>');

            foreach ($table->indexes as $index) {
                $this->index($output, $index);
            }
        }

        if ($table->foreignKeys !== []) {
            $output->writeln('    <fg=gray>Foreign keys</>');

            foreach ($table->foreignKeys as $key) {
                $this->foreignKey($output, $key);
            }
        }

        $output->writeln('');
    }

    private function column(OutputInterface $output, ColumnDiff $column): void
    {
        $rendered = $column->to?->render() ?? $column->from?->render() ?? '';

        if ($column->type === ChangeType::Changed) {
            $output->writeln('    '.$this->marker($column->type).' '.$column->name
                .' '.($column->from?->render() ?? ''));
            $output->writeln('      <fg=gray>→</> '.($column->to?->render() ?? ''));

            return;
        }

        $output->writeln('    '.$this->marker($column->type).' '.$column->name.' '.$rendered);
    }

    private function index(OutputInterface $output, IndexDiff $index): void
    {
        if ($index->type === ChangeType::Changed) {
            $output->writeln('    '.$this->marker($index->type).' '.$index->name
                .' '.($index->from?->render() ?? ''));
            $output->writeln('      <fg=gray>→</> '.($index->to?->render() ?? ''));

            return;
        }

        $rendered = $index->to?->render() ?? $index->from?->render() ?? '';

        $output->writeln('    '.$this->marker($index->type).' '.$index->name.' '.$rendered);
    }

    private function foreignKey(OutputInterface $output, ForeignKeyDiff $key): void
    {
        if ($key->type === ChangeType::Changed) {
            $output->writeln('    '.$this->marker($key->type).' '.$key->name
                .' '.($key->from?->render() ?? ''));
            $output->writeln('      <fg=gray>→</> '.($key->to?->render() ?? ''));

            return;
        }

        $rendered = $key->to?->render() ?? $key->from?->render() ?? '';

        $output->writeln('    '.$this->marker($key->type).' '.$key->name.' '.$rendered);
    }

    private function marker(ChangeType $type): string
    {
        return '<fg='.$type->colour().'>'.$type->marker().'</>';
    }
}
