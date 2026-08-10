<?php

declare(strict_types=1);

use Difflock\Migration\Rules\AddIndexRule;
use Difflock\Migration\Rules\AddNotNullColumnRule;
use Difflock\Migration\Rules\ChangeColumnRule;
use Difflock\Migration\Rules\DropColumnRule;
use Difflock\Migration\Rules\DropIndexRule;
use Difflock\Migration\Rules\DropTableRule;
use Difflock\Migration\Rules\ForeignKeyRule;
use Difflock\Migration\Rules\LargeTableRule;
use Difflock\Migration\Rules\RenameColumnRule;

return [

    /*
    |--------------------------------------------------------------------------
    | Difflock Master Switch
    |--------------------------------------------------------------------------
    |
    | When disabled, Difflock's commands refuse to run rather than reporting a
    | clean result. A check that goes green because it never looked is worse
    | than no check at all, so this is the one case where nothing found is not
    | the same as nothing wrong.
    |
    */

    'enabled' => env('DIFFLOCK_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The connection Difflock inspects. Null means the application's default.
    | Difflock only ever reads: it introspects the schema and asks the database
    | how large its tables are, and it has no code path that writes to the
    | connection it inspects. A read-only role is enough for all of it.
    |
    */

    'connection' => env('DIFFLOCK_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Schema Baseline
    |--------------------------------------------------------------------------
    |
    | Where the recorded schema lives. Drift is measured against this file, and
    | it is meant to be committed:
    |
    |     php artisan difflock:diff --save
    |
    | Difflock deliberately does not reconstruct an "expected" schema from your
    | migration source. Migrations are executable PHP — they branch on config,
    | they loop, they call DB::statement() — and any such reconstruction would
    | be a guess presented as a fact. A recorded baseline is a schema that was
    | actually observed, so a difference against it means something.
    |
    */

    'baseline' => env('DIFFLOCK_BASELINE', database_path('difflock/schema.json')),

    /*
    |--------------------------------------------------------------------------
    | What the Baseline Records
    |--------------------------------------------------------------------------
    |
    | The baseline is the one file Difflock publishes: you are told to commit
    | it, so it is worth knowing what goes in. It holds structure only — table,
    | column and index names, types, nullability, defaults, comments and
    | foreign keys. It never holds a single row of data, and never a credential.
    |
    | For a private repository that is close to no new exposure: your migrations
    | already describe the same structure. Two things are worth a thought before
    | committing it anyway. It records the schema as it *is*, including anything
    | created outside a migration — that is the point of drift detection, and it
    | means the file can say more than your migrations do. And if the repository
    | is ever public, it hands a reader the exact shape of every table.
    |
    | Defaults and comments are the only fields that carry free text, so they
    | are the only ones you can decline to record. Turning defaults off means a
    | default changing is no longer drift; nothing else changes, and the rules,
    | which read the live database rather than this file, are unaffected.
    |
    | To exclude whole tables, use 'ignore.tables' below. To keep the file out
    | of git entirely, add it to .gitignore and record it in CI instead — you
    | keep "did this branch change the schema" and lose "did production drift".
    |
    */

    'snapshot' => [
        'defaults' => env('DIFFLOCK_SNAPSHOT_DEFAULTS', true),
        'comments' => env('DIFFLOCK_SNAPSHOT_COMMENTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk Threshold
    |--------------------------------------------------------------------------
    |
    | The lowest risk level that fails difflock:check and difflock:lint. One of
    | safe, low, medium, high or critical.
    |
    | Critical is the default because a gate people turn off is worse than a
    | quieter one they leave on. Once critical findings are consistently zero,
    | tightening this to high is the natural next step.
    |
    */

    'risk' => [
        'fail_on' => env('DIFFLOCK_FAIL_ON', 'critical'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Protection
    |--------------------------------------------------------------------------
    |
    | difflock:migrate analyses the pending migrations and hands over to
    | Laravel's own migrate only if nothing is at or above 'block_on'.
    |
    | Nothing here affects `php artisan migrate`. Difflock hooks nothing
    | globally and never changes when your migrations run: installing it does
    | not put a gate in front of a command you did not ask it to guard.
    |
    */

    'protection' => [
        'enabled' => env('DIFFLOCK_PROTECTION_ENABLED', true),
        'block_on' => env('DIFFLOCK_BLOCK_ON', 'critical'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Size Thresholds
    |--------------------------------------------------------------------------
    |
    | The row counts at which a table starts being treated as big enough for an
    | index build or a column rewrite to be felt. Both are read from database
    | metadata rather than counted, and are estimates on MySQL and PostgreSQL.
    |
    | A table whose size cannot be determined is never treated as large — and
    | never as empty either. Unknown is its own answer, and the rules say so.
    |
    */

    'thresholds' => [
        'medium_table_rows' => env('DIFFLOCK_MEDIUM_TABLE_ROWS', 100_000),
        'large_table_rows' => env('DIFFLOCK_LARGE_TABLE_ROWS', 1_000_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Paths
    |--------------------------------------------------------------------------
    |
    | Extra directories to look for migrations in, on top of the ones the
    | application's migrator already knows about. Most applications need none
    | of these; packages that ship their own migration paths might.
    |
    */

    'migrations' => [
        'paths' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rules
    |--------------------------------------------------------------------------
    |
    | The rules the analyzer runs. Remove one to switch it off entirely; add
    | your own to have it run alongside the built-ins.
    |
    | A rule is any class implementing Difflock\Contracts\MigrationRule. It is
    | resolved through the container, so it may take constructor dependencies.
    | You can also register one at runtime from a service provider's boot():
    |
    |     Difflock::rule(NoTriggersRule::class);
    |
    | Rules are keyed by identifier, last one winning, so registering a rule
    | that answers to 'drop-column' replaces the built-in of that name.
    |
    */

    'rules' => [
        DropTableRule::class,
        DropColumnRule::class,
        RenameColumnRule::class,
        ChangeColumnRule::class,
        AddNotNullColumnRule::class,
        AddIndexRule::class,
        DropIndexRule::class,
        ForeignKeyRule::class,
        LargeTableRule::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignore
    |--------------------------------------------------------------------------
    |
    | Findings matching any of these are dropped from the report. Every entry
    | accepts * wildcards.
    |
    | Filtering happens after the rules have run, so the ignore list can only
    | ever remove findings — a mistake in it cannot make a rule report
    | something it would not otherwise have reported.
    |
    | 'tables' goes further than the other two: an ignored table is left out of
    | schema inspection altogether, so it appears in no diff and is never
    | written to the committed baseline. That is the control to reach for when
    | a table's very structure is something you would rather not publish.
    |
    */

    'ignore' => [
        'rules' => [],
        'tables' => [],
        'migrations' => [],
    ],

];
