<?php

declare(strict_types=1);

namespace Difflock\Console\Formatters;

use Difflock\CheckupResult;
use Difflock\Diff\ChangeType;
use Difflock\Diff\SchemaDiff;
use Difflock\Diff\TableDiff;
use Difflock\Migration\MigrationFinding;
use Difflock\Risk\RiskLevel;

/**
 * The whole of a Difflock run as one self-contained HTML file.
 *
 * Built for the place these reports actually get read: a CI artifact somebody opens
 * from a pull request. That constrains it more than it might seem.
 *
 * **Nothing is fetched.** No stylesheet, no font, no script — a build artifact
 * opened from a `file://` URL has no network, and a report that renders as unstyled
 * text in the one context it exists for is a report nobody opens twice.
 *
 * **Everything is escaped.** Table names, column names and rule messages all come
 * from a database and from migration source, neither of which this package controls.
 * A column called `<script>` must render as a column called `<script>`.
 *
 * **It reads in both themes**, because half of the people opening it will be in a
 * dark one and the other half will not.
 */
final class HtmlReport
{
    public function render(CheckupResult $result, string $generatedAt, ?string $application = null): string
    {
        $summary = $result->report->summary();
        $failed = $result->failed();

        $title = 'Difflock report'.($application === null ? '' : ' — '.$application);

        return implode("\n", [
            '<!doctype html>',
            '<html lang="en"><head><meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>'.$this->escape($title).'</title>',
            '<style>'.$this->styles().'</style>',
            '</head><body><main>',
            '<header class="head">',
            '<h1>Difflock</h1>',
            '<p class="verdict '.($failed ? 'fail' : 'pass').'">'.($failed ? 'FAIL' : 'PASS').'</p>',
            '<p class="meta">'.$this->escape($generatedAt)
                .($application === null ? '' : ' · '.$this->escape($application)).'</p>',
            '</header>',
            $this->tally($summary->counts, $summary->total, count($result->report->accepted)),
            $this->schema($result),
            $this->findings($result->report->findings),
            $this->warnings($result->report->warnings(), $result->report->databaseAvailable),
            '<footer>Difflock analyses migration source statically and reads the live schema. '
                .'It reports what it could not determine rather than guessing.</footer>',
            '</main></body></html>',
        ]);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function tally(array $counts, int $total, int $accepted): string
    {
        $cells = '';

        foreach (RiskLevel::ascending() as $level) {
            $count = $counts[$level->value] ?? 0;

            $cells .= '<div class="stat '.$level->value.($count > 0 ? ' on' : '').'">'
                .'<span class="n">'.$count.'</span>'
                .'<span class="l">'.$this->escape(ucfirst($level->value)).'</span></div>';
        }

        $note = $accepted > 0
            ? '<p class="meta">'.$accepted.' previously accepted finding'.($accepted === 1 ? '' : 's')
                .' not shown.</p>'
            : '';

        return '<section><h2>Risk</h2><div class="stats">'.$cells.'</div>'
            .'<p class="meta">'.$total.' finding'.($total === 1 ? '' : 's').'.</p>'.$note.'</section>';
    }

    private function schema(CheckupResult $result): string
    {
        if ($result->baselineError !== null) {
            return '<section><h2>Schema</h2><p class="bad">The recorded baseline could not be read: '
                .$this->escape($result->baselineError).'</p></section>';
        }

        if (! $result->baselineRecorded) {
            return '<section><h2>Schema</h2><p class="muted">No baseline recorded, so drift was not '
                .'checked. Record one with <code>php artisan difflock:diff --save</code>.</p></section>';
        }

        if (! $result->drifted() || ! $result->drift instanceof SchemaDiff) {
            return '<section><h2>Schema</h2><p class="good">No drift detected.</p></section>';
        }

        $body = '';

        foreach ($result->drift->tables as $table) {
            $body .= $this->table($table);
        }

        return '<section><h2>Schema drift</h2><p class="meta">'.$result->drift->count()
            .' difference'.($result->drift->count() === 1 ? '' : 's').' from the baseline.</p>'
            .$body.'</section>';
    }

    private function table(TableDiff $table): string
    {
        $rows = '';

        foreach ($table->columns as $column) {
            $rows .= $this->change($column->type, $column->name,
                $column->to?->render() ?? $column->from?->render() ?? '');
        }

        foreach ($table->indexes as $index) {
            $rows .= $this->change($index->type, $index->name,
                $index->to?->render() ?? $index->from?->render() ?? '');
        }

        foreach ($table->foreignKeys as $key) {
            $rows .= $this->change($key->type, $key->name,
                $key->to?->render() ?? $key->from?->render() ?? '');
        }

        if ($table->type !== ChangeType::Changed) {
            $rows = $this->change($table->type, 'table '.$table->type->value, '');
        }

        return '<div class="tbl"><h3>'.$this->escape($table->name).'</h3>'.$rows.'</div>';
    }

    private function change(ChangeType $type, string $name, string $detail): string
    {
        return '<div class="chg '.$type->value.'"><span class="mk">'.$this->escape($type->marker()).'</span>'
            .'<span class="nm">'.$this->escape($name).'</span>'
            .($detail === '' ? '' : '<span class="dt">'.$this->escape($detail).'</span>').'</div>';
    }

    /**
     * Findings grouped exactly as the console groups them — by rule, risk and the
     * prose they carry — so the two renderings never tell different stories.
     *
     * @param  list<MigrationFinding>  $findings
     */
    private function findings(array $findings): string
    {
        if ($findings === []) {
            return '<section><h2>Migrations</h2><p class="good">Nothing to report.</p></section>';
        }

        $groups = [];

        foreach ($findings as $finding) {
            $groups[$finding->risk->value."\0".$finding->rule."\0".$finding->explanation][] = $finding;
        }

        $body = '';

        foreach ($groups as $group) {
            $first = $group[0];
            $count = count($group);

            $occurrences = '';

            foreach ($group as $finding) {
                $occurrences .= '<li><span class="msg">'.$this->escape($finding->message).'</span>'
                    .'<span class="where">'.$this->escape($finding->migration)
                    .($finding->line === null ? '' : ':'.$finding->line).'</span>'
                    .$this->flags($finding).'</li>';
            }

            $body .= '<article class="grp '.$first->risk->value.'">'
                .'<h3><span class="pill '.$first->risk->value.'">'.$this->escape($first->risk->label()).'</span> '
                .$this->escape($first->rule)
                .($count === 1 ? '' : ' <span class="muted">'.$count.' findings</span>').'</h3>'
                .'<p>'.$this->escape($first->explanation).'</p>'
                .($first->suggestion === null ? '' : '<p class="fix">'.$this->escape($first->suggestion).'</p>')
                .'<ul>'.$occurrences.'</ul></article>';
        }

        return '<section><h2>Migrations</h2>'.$body.'</section>';
    }

    private function flags(MigrationFinding $finding): string
    {
        $flags = '';

        if ($finding->destructive) {
            $flags .= '<span class="flag bad">destructive</span>';
        }

        if (! $finding->reversible) {
            $flags .= '<span class="flag bad">not reversible</span>';
        }

        if ($finding->conditional) {
            $flags .= '<span class="flag">conditional</span>';
        }

        return $flags;
    }

    /**
     * @param  list<string>  $warnings
     */
    private function warnings(array $warnings, bool $available): string
    {
        if ($warnings === [] && $available) {
            return '';
        }

        $items = $available
            ? ''
            : '<li>The database could not be reached, so no finding took table size or the live schema '
                .'into account.</li>';

        foreach ($warnings as $warning) {
            $items .= '<li>'.$this->escape($warning).'</li>';
        }

        return '<section><h2>Not fully analysed</h2><ul class="warn">'.$items.'</ul></section>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function styles(): string
    {
        return <<<'CSS'
        :root{--bg:#fff;--fg:#16181d;--mut:#666e7a;--line:#e4e7ec;--card:#f8f9fb;
        --safe:#2f855a;--low:#2b6cb0;--medium:#b7791f;--high:#c05621;--critical:#c53030}
        @media(prefers-color-scheme:dark){:root{--bg:#14161a;--fg:#e6e8ec;--mut:#9aa3ae;
        --line:#2a2e36;--card:#1b1e24;--safe:#68d391;--low:#63b3ed;--medium:#f6ad55;
        --high:#fc8181;--critical:#feb2b2}}
        *{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--fg);
        font:15px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
        main{max-width:60rem;margin:0 auto;padding:2.5rem 1.25rem 4rem}
        h1{font-size:1.6rem;margin:0}
        h2{font-size:1rem;text-transform:uppercase;letter-spacing:.08em;color:var(--mut);
        margin:2.5rem 0 .75rem;padding-bottom:.4rem;border-bottom:1px solid var(--line)}
        h3{font-size:1rem;margin:0 0 .5rem}
        .head{display:flex;align-items:baseline;gap:.75rem;flex-wrap:wrap}
        .verdict{font-weight:700;letter-spacing:.06em;margin:0}
        .verdict.pass{color:var(--safe)}.verdict.fail{color:var(--critical)}
        .meta,.muted{color:var(--mut);font-size:.875rem;margin:.25rem 0}
        .good{color:var(--safe)}.bad{color:var(--critical)}
        .stats{display:flex;flex-wrap:wrap;gap:.5rem}
        .stat{flex:1 1 6rem;background:var(--card);border:1px solid var(--line);
        border-radius:.5rem;padding:.75rem;text-align:center;opacity:.55}
        .stat.on{opacity:1}
        .stat .n{display:block;font-size:1.5rem;font-weight:700}
        .stat .l{display:block;font-size:.75rem;color:var(--mut);text-transform:uppercase;
        letter-spacing:.06em}
        .stat.safe .n{color:var(--safe)}.stat.low .n{color:var(--low)}
        .stat.medium .n{color:var(--medium)}.stat.high .n{color:var(--high)}
        .stat.critical .n{color:var(--critical)}
        .grp{background:var(--card);border:1px solid var(--line);border-left-width:3px;
        border-radius:.5rem;padding:1rem;margin:0 0 .75rem}
        .grp.safe{border-left-color:var(--safe)}.grp.low{border-left-color:var(--low)}
        .grp.medium{border-left-color:var(--medium)}.grp.high{border-left-color:var(--high)}
        .grp.critical{border-left-color:var(--critical)}
        .grp p{margin:.4rem 0}
        .fix{color:var(--mut)}.fix::before{content:"→ "}
        .pill{font-size:.7rem;font-weight:700;letter-spacing:.06em;padding:.1rem .45rem;
        border-radius:.25rem;border:1px solid currentColor}
        .pill.safe{color:var(--safe)}.pill.low{color:var(--low)}
        .pill.medium{color:var(--medium)}.pill.high{color:var(--high)}
        .pill.critical{color:var(--critical)}
        .grp ul{list-style:none;margin:.75rem 0 0;padding:0;font-size:.875rem}
        .grp li{padding:.3rem 0;border-top:1px solid var(--line);display:flex;
        gap:.5rem;flex-wrap:wrap;align-items:baseline}
        .msg{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
        .where{color:var(--mut);font-size:.8rem}
        .flag{font-size:.7rem;color:var(--mut);border:1px solid var(--line);
        border-radius:.25rem;padding:0 .3rem}
        .flag.bad{color:var(--critical)}
        .tbl{margin:0 0 1rem}
        .chg{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.85rem;
        display:flex;gap:.5rem;padding:.15rem 0}
        .chg .mk{width:1ch;font-weight:700}
        .chg.added .mk{color:var(--safe)}.chg.removed .mk{color:var(--critical)}
        .chg.changed .mk{color:var(--medium)}
        .dt{color:var(--mut)}
        .warn{color:var(--medium);font-size:.875rem}
        code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.85em}
        footer{margin-top:3rem;padding-top:1rem;border-top:1px solid var(--line);
        color:var(--mut);font-size:.8rem}
        CSS;
    }
}
