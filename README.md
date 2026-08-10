# Difflock

[![Latest Version](https://img.shields.io/packagist/v/heyosseus/difflock.svg)](https://packagist.org/packages/heyosseus/difflock)
[![Total Downloads](https://img.shields.io/packagist/dt/heyosseus/difflock.svg)](https://packagist.org/packages/heyosseus/difflock)
[![Tests](https://img.shields.io/github/actions/workflow/status/Heyosseus/difflock/tests.yml?branch=main&label=tests)](https://github.com/Heyosseus/difflock/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/heyosseus/difflock.svg)](https://packagist.org/packages/heyosseus/difflock)

**Diff, analyze, and protect your Laravel database schema.**

Difflock reads your migrations and your database, tells you what is about to change, how badly it could go, and stops the changes that should not run unattended.

```
Diff what changed.  Analyze the risk.  Lock dangerous changes.
```

```bash
composer require heyosseus/difflock --dev
php artisan difflock:lint
```

```text
  Difflock  ·  Migration Analysis
  ────────────────────────────────────────

  2026_08_10_120000_remove_legacy_token

    ✗ CRITICAL DROP COLUMN users.legacy_token
      drop-column:14  ·  destructive, not reversible
      Dropping a column destroys the values in it. A `down()` that adds the
      column back gives you the column and not one row of what was in it.
      The table holds 4,921,000 rows.
      → Stop reading and writing the column in application code first,
      deploy that, and drop it in a later migration once you are sure
      nothing needs it.

  Risk
    ✓ Safe:      0
    ⚠ Low:       1
    ⚠ Medium:    0
    ✗ High:      0
    ✗ Critical:  1

  3 migrations analysed.
```

---

## Contents

1. [Overview](#overview)
2. [Why Difflock?](#why-difflock)
3. [Installation](#installation)
4. [Quick start](#quick-start)
5. [Schema diff](#schema-diff)
6. [Migration linting](#migration-linting)
7. [Risk levels](#risk-levels)
8. [Migration protection](#migration-protection)
9. [CI](#ci)
10. [JSON output](#json-output)
11. [Configuration](#configuration)
12. [Custom rules](#custom-rules)
13. [Programmatic API](#programmatic-api)
14. [Supported databases](#supported-databases)
15. [Limitations](#limitations)
16. [Architecture](#architecture)
17. [Contributing](#contributing)
18. [License](#license)

---

## Overview

Difflock has three jobs, and it keeps them separate.

```text
                    DIFFLOCK
                       │
          ┌────────────┼────────────┐
          │            │            │
         DIFF        ANALYZE       LOCK
          │            │            │
     What changed?  Is it risky?  Should it run?
          │            │            │
          └────────────┼────────────┘
                       │
                    DATABASE
```

| Command | Answers |
| --- | --- |
| `php artisan difflock` | All of it, in one screen |
| `php artisan difflock:diff` | Has the schema drifted from the recorded baseline? |
| `php artisan difflock:lint` | What will the pending migrations do, and how risky is it? |
| `php artisan difflock:check` | Both, with an exit code CI can act on |
| `php artisan difflock:migrate` | Migrate — but only if it is safe to |

Every command has real help text. `php artisan help difflock:lint` is worth reading once.

## Why Difflock?

Code review catches the migration that is *wrong*. It rarely catches the migration that is *correct and dangerous*, because that one looks fine:

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('status');            // fails on a populated table
    $table->dropColumn('legacy_token');  // no down() brings the data back
    $table->renameColumn('name', 'full_name');  // breaks the release still running
});
```

Each of those passes review, passes CI against an empty database, and behaves differently against production. Difflock reads them the way a careful reviewer would, with the live schema and the table sizes in front of it.

It is deliberately conservative about what it claims. It will tell you an index build reads every row; it will **not** tell you it takes a lock, because that depends on an engine and a version it cannot see from a migration file. Everywhere the honest answer is "it depends", Difflock says so and tells you what it depends on.

**It never writes to the database it inspects.** Introspection and size metadata are reads. The only command that writes anything is `difflock:migrate`, and all it does is hand over to Laravel's own `migrate` once it has decided the migrations are safe.

## Installation

```bash
composer require heyosseus/difflock --dev
```

Laravel discovers the package automatically. Publish the config if you want to tune it:

```bash
php artisan vendor:publish --tag=difflock-config
```

**Requirements**

- PHP 8.3+
- Laravel 12 or 13
- MySQL, MariaDB, PostgreSQL or SQLite

Laravel 11 is deliberately not supported. Every one of its releases is now covered by a security advisory, so Composer's default policy refuses to install any of them — claiming support for a major nobody can install would be a promise the package cannot keep.

No Doctrine DBAL. Laravel 11 moved schema introspection into the framework, so Difflock uses that and carries no driver-specific SQL of its own beyond one cheap metadata query for table sizes.

## Quick start

```bash
# 1. Record the schema you have agreed on, and commit the file.
php artisan difflock:diff --save

# 2. Ask what the pending migrations will do.
php artisan difflock:lint

# 3. Put both in CI.
php artisan difflock:check --ci
```

## Schema diff

`difflock:diff` compares **two schemas that were both actually observed**.

```bash
php artisan difflock:diff --save     # record the baseline
php artisan difflock:diff            # compare the live schema against it
```

```text
  Difflock  ·  Schema Diff
  ────────────────────────────────────────

  users
    + phone VARCHAR(50) NULL

    ~ email VARCHAR(255) NOT NULL
      → VARCHAR(320) NOT NULL

    - legacy_token VARCHAR(255) NOT NULL

    Indexes
    + users_phone_index INDEX (phone)
    - users_old_index INDEX (old_column)

  4 changes detected.
```

`+` gained, `-` lost, `~` altered — and a `~` shows what it was above what it becomes. The markers carry the meaning, so the output reads identically under `--no-ansi`, in a CI log, or pasted into a pull request.

It detects tables, columns, indexes and foreign keys added, removed and changed — including nullability, defaults, lengths, precision, uniqueness and referential actions.

To compare two connections instead of a baseline:

```bash
php artisan difflock:diff --from=staging --to=production
```

### Why a recorded baseline

Drift means "the database no longer matches what we agreed on". Difflock makes that a claim you can check by comparing against a snapshot somebody deliberately recorded and committed, rather than against a schema reconstructed from migration source — which, for reasons in [Limitations](#limitations), cannot be made reliable.

The baseline is a versioned JSON file. A baseline that exists and cannot be read is an **error**, not an empty schema: exit code 2, never a green tick.

## Migration linting

```bash
php artisan difflock:lint            # pending migrations
php artisan difflock:lint --all      # every migration file
php artisan difflock:lint --path=database/migrations/legacy
```

Only pending migrations are analysed by default. A migration that has already run cannot be made safer by a finding, and a build that fails over a drop committed two years ago is a build nobody keeps green.

### Built-in rules

| Rule | Detects | Risk |
| --- | --- | --- |
| `drop-table` | `Schema::drop()`, `dropIfExists()`, `dropAllTables()` | Critical |
| `drop-column` | `dropColumn()`, `dropTimestamps()`, `dropSoftDeletes()`, `dropConstrainedForeignId()`, … | Critical |
| `rename-column` | `renameColumn()`, `Schema::rename()` | High |
| `change-column` | `->change()` — type, length, nullability, precision, defaults | Computed |
| `add-not-null-column` | A NOT NULL column with no default added to a table with rows | Low → High |
| `add-index` | `index()`, `unique()`, `fullText()`, … on an existing table | Low → High |
| `drop-index` | `dropIndex()`, `dropUnique()`, `dropPrimary()` | Low → High |
| `foreign-key` | Added, dropped, and cascading constraints | Low → High |
| `large-table` | Any alter on a table above the configured size | Medium |

Two of them earn their place immediately.

**`add-not-null-column`** is the migration that passes review, passes CI against an empty database, and fails in production — a NOT NULL column with no default has nothing to put in the rows already there, and most engines refuse the statement. Difflock scales it by the actual row count: high when the table is known to hold rows, low when it is known to be empty, medium when the count is unknown, and it says which.

**`foreign-key`** flags `cascadeOnDelete()`. Four keystrokes that turn `$user->delete()` into a delete of every order, invoice and line item, inside the database, with no model events, no observers and no soft deletes. The migration that introduces it is the last moment anybody looks at it on purpose.

### `change-column` computes its risk

`->change()` covers everything from widening a `varchar`, which costs nothing, to turning a nullable text column into a NOT NULL integer, which can fail partway through a deploy. Difflock compares the declaration against the column as it exists now and reports the worst thing it finds:

| Change | Risk |
| --- | --- |
| Nullable → NOT NULL, table has rows | High |
| Length reduced, table has rows | High |
| Type family changed (text → integer), table has rows | High |
| Default dropped | Medium |
| Live column could not be read | Medium |
| Length increased, NOT NULL → nullable, nothing changed | Low |

Type comparison is by *family* — text, integer, decimal, datetime, json, uuid — not by name, so `string()` against `character varying(255)` is correctly not a change, and `integer()` against `varchar(50)` correctly is.

## Risk levels

```php
RiskLevel::Safe      // nothing here can lose data or break a running application
RiskLevel::Low       // reversible, unlikely to be felt
RiskLevel::Medium    // reversible, capable of noticeable impact on a busy table
RiskLevel::High      // can break the running application, or fail partway through
RiskLevel::Critical  // destroys data or structure no down() brings back
```

Levels are **deterministic**. Every rule documents the conditions under which it returns each one, and the same migration against the same database always produces the same level. Nothing is scored, weighted or inferred — a level is the name of a branch a rule took.

Every finding also carries two facts rather than opinions:

- **destructive** — the operation removes data or structure.
- **reversible** — the migration has a `down()` with a body.

`reversible` does **not** mean the data comes back. A dropped column's `down()` recreates the column and not one row of what was in it. Rules that destroy data set `destructive` and say so, whatever `down()` looks like.

## Migration protection

```bash
php artisan difflock:migrate
```

Analyses the pending migrations. If nothing reaches the block level it hands over to Laravel's own `migrate`, unchanged. If something does:

```text
  Difflock  ·  Migration Guard
  ────────────────────────────────────────

    ✗ CRITICAL DROP COLUMN users.legacy_token
      drop-column:14  ·  destructive, not reversible
      …

    ⚠ HIGH     ADD INDEX orders (customer_id)
      add-index:16
      …

  Migration blocked.

  Review the findings above. Re-run with --allow-risky once you have decided
  they are acceptable, or fix the migrations and try again.
```

Nothing touched the database.

```bash
php artisan difflock:migrate --dry-run      # analyse and print, never write
php artisan difflock:migrate --allow-risky  # run anyway, deliberately
php artisan difflock:migrate --force        # Laravel's own flag, passed straight through
```

`--allow-risky` is deliberately not spelled `--force`. Bypassing Difflock and skipping Laravel's production confirmation are different decisions and should not share a flag.

### What Difflock will not do

- It does **not** hook `php artisan migrate`. Installing Difflock changes nothing about when your migrations run. A package that silently changes what `migrate` does is a package that can break a pipeline it was never meant to be part of — and a guard you have to opt into is a guard whose absence is visible.
- It does **not** modify your data, drop anything, rewrite migrations, or "fix" drift.
- `--dry-run` has no code path that reaches the database with anything but a read.

## CI

```bash
php artisan difflock:check --ci
```

```text
  Difflock CI
  ────────────────────────────────────────

  Schema
    ✓ No drift detected
  Migrations
    ✗ 2 findings, worst CRITICAL (threshold CRITICAL)

    ✗ CRITICAL DROP COLUMN users.legacy_token
      …

  Result: FAIL
```

| Exit code | Meaning |
| --- | --- |
| `0` | Nothing at or above the threshold |
| `1` | Findings above the threshold, or the schema has drifted |
| `2` | Configuration or runtime error |

**Treat 2 as a failure.** It means the check did not run — a disabled package, an unparseable `--fail-on`, an unreadable baseline — which is different from running and finding nothing.

### GitHub Actions

```yaml
name: Difflock

on:
  pull_request:

jobs:
  schema:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v5

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - run: composer install --no-interaction --prefer-dist

      - run: php artisan difflock:check --ci
```

### GitLab CI

```yaml
difflock:
  image: php:8.3-cli
  script:
    - composer install --no-interaction --prefer-dist
    - php artisan difflock:check --ci
```

Difflock runs with no database attached. Every rule that reads only the source — the drop, the rename, the cascade — still fires; the size-dependent ones report that the count was unknown, and the report says at the top that it ran blind. It never quietly grades on a curve.

## JSON output

Every command supports `--format=json`.

```bash
php artisan difflock:lint --format=json | jq '.findings[] | select(.destructive)'
```

```json
{
    "difflock": 1,
    "status": "failed",
    "risk": "critical",
    "threshold": "critical",
    "migrations": ["2026_08_10_120000_remove_legacy_token"],
    "analyzed": 3,
    "counts": { "safe": 0, "low": 1, "medium": 0, "high": 0, "critical": 1 },
    "database_available": true,
    "warnings": [],
    "findings": [
        {
            "rule": "drop-column",
            "risk": "critical",
            "migration": "2026_08_10_120000_remove_legacy_token",
            "table": "users",
            "column": "legacy_token",
            "message": "DROP COLUMN users.legacy_token",
            "explanation": "Dropping a column destroys the values in it. …",
            "suggestion": "Stop reading and writing the column in application code first, …",
            "destructive": true,
            "reversible": false,
            "conditional": false,
            "line": 14
        }
    ]
}
```

Notes on the shape, which is documented and stable:

- **No ANSI, ever.** The document is written raw, so `| jq` works whether or not the terminal is a TTY.
- `difflock` is the format version. Keys are added in minor versions; removing or repurposing one needs a major.
- The subject appears under a key naming what it *is* — `column`, `index`, `constraint` or `table` — so a consumer can tell a dropped index from a dropped column without parsing prose.
- In `difflock:check`, `schema` is `null` — not an empty diff — when no baseline was recorded. "No drift" and "nobody looked" are different answers.
- `warnings` lists everything static analysis could not fully read. A clean report over a file Difflock only half understood is not a clean report, and this is where it says so.

## Configuration

`config/difflock.php`, in full:

```php
return [
    'enabled' => env('DIFFLOCK_ENABLED', true),

    'connection' => env('DIFFLOCK_CONNECTION'),

    'baseline' => env('DIFFLOCK_BASELINE', database_path('difflock/schema.json')),

    'risk' => [
        'fail_on' => env('DIFFLOCK_FAIL_ON', 'critical'),
    ],

    'protection' => [
        'enabled' => env('DIFFLOCK_PROTECTION_ENABLED', true),
        'block_on' => env('DIFFLOCK_BLOCK_ON', 'critical'),
    ],

    'thresholds' => [
        'medium_table_rows' => env('DIFFLOCK_MEDIUM_TABLE_ROWS', 100_000),
        'large_table_rows' => env('DIFFLOCK_LARGE_TABLE_ROWS', 1_000_000),
    ],

    'migrations' => [
        'paths' => [],
    ],

    'rules' => [ /* the nine built-ins */ ],

    'ignore' => [
        'rules' => [],       // 'add-index', 'drop-*'
        'tables' => [],      // 'telescope_*'
        'migrations' => [],  // '2019_*'
    ],
];
```

`enabled => false` makes the commands **refuse to run** rather than report a clean result. A check that goes green because it never looked is worse than no check.

Ignores are matched against findings *after* the rules have run, so the ignore list can only ever remove findings — a mistake in it cannot make a rule report something it would not otherwise have reported.

## Custom rules

A rule implements one interface and knows nothing about Artisan, rendering, or the database:

```php
use Difflock\Contracts\MigrationRule;
use Difflock\Migration\MigrationContext;
use Difflock\Migration\Subject;
use Difflock\Risk\RiskLevel;

final class NoUuidPrimaryKeysRule implements MigrationRule
{
    public function identifier(): string
    {
        return 'no-uuid-primary-keys';
    }

    public function analyze(MigrationContext $context): array
    {
        $findings = [];

        foreach ($context->operations('uuid') as $operation) {
            if (! $operation->hasModifier('primary')) {
                continue;
            }

            $findings[] = $context->finding(
                rule: $this->identifier(),
                risk: RiskLevel::Medium,
                message: 'UUID primary key on '.$context->tableName(),
                explanation: 'Random primary keys scatter inserts across the index.',
                suggestion: 'Use an auto-incrementing key, or a ULID.',
                subject: $operation->stringArgument(0),
                subjectType: Subject::Column,
                operation: $operation,
            );
        }

        return $findings;
    }
}
```

Register it either way:

```php
// A service provider's boot()
use Difflock\Facades\Difflock;

Difflock::rule(NoUuidPrimaryKeysRule::class);
```

```php
// config/difflock.php
'rules' => [
    // …
    NoUuidPrimaryKeysRule::class,
],
```

Rules are resolved through the container, so they may take constructor dependencies. Registration order does not matter. Rules are keyed by identifier with the last one winning, so registering a rule that answers to `drop-column` **replaces** the built-in of that name.

The context gives a rule everything it is allowed to know:

```php
$context->migrationName();     // '2026_08_10_120000_remove_legacy_token'
$context->tableName();         // 'users', or null if it was not a literal
$context->liveTable();         // the table as it exists now, or null
$context->rows();              // roughly how many rows, or null if unknown
$context->reversible();        // whether down() has a body
$context->operations('index'); // the blueprint chains starting with index()
$context->database->thresholds;
$context->database->available; // false when there was no database to ask
```

`null` from `rows()` means **unknown**, never zero. A rule that confused the two would call a migration against an eight-million-row table safe.

Testing a rule needs no database:

```php
use Difflock\Database\FixedTableStatistics;

$statistics = new FixedTableStatistics(['orders' => 8_421_392]);
```

## Programmatic API

```php
use Difflock\Facades\Difflock;

$schema  = Difflock::inspect();          // the live schema
$diff    = Difflock::diff('a', 'b');     // two connections compared
$drift   = Difflock::drift();            // live vs recorded baseline
$report  = Difflock::analyze();          // the whole migration report
$findings = Difflock::lint();            // just the findings
$decision = Difflock::guard();           // should the pending migrations run?
```

The facade is a convenience, never a requirement. Nothing in the package depends on it, and every contract is injectable:

```php
use Difflock\Contracts\MigrationAnalyzer;
use Difflock\Contracts\SchemaDiffer;

public function __construct(
    private MigrationAnalyzer $analyzer,
    private SchemaDiffer $differ,
) {}
```

## Supported databases

| Driver | Schema | Row counts | Table bytes |
| --- | --- | --- | --- |
| MySQL / MariaDB | Yes | Estimated (`information_schema`) | Yes |
| PostgreSQL | Yes | Estimated (`pg_class.reltuples`) | Yes |
| SQLite | Yes | Exact (`COUNT(*)`) | No |

Row counts come from database metadata, not from scanning tables. Difflock is meant to be safe to point at production; a tool that reads every row to find out how big a table is has become the problem it was installed to prevent.

Where a driver will not answer, Difflock reports **unknown** and the rules become more cautious, not less. PostgreSQL's `reltuples = -1` on a never-analysed table is unknown, not zero.

## Limitations

Read this section. It is why the rest of the output can be trusted.

**Laravel migrations are arbitrary executable PHP.** Difflock reads them statically — it never loads or runs a migration class, because a linter that boots the code it is linting is a linter that can be made to drop your tables. Static analysis cannot resolve everything:

```php
if (config('features.phone')) {          // may or may not run
    Schema::table(...);
}

foreach ($this->tenantTables() as $t) {  // table names unknown
    Schema::table($t, ...);
}

DB::statement('ALTER TABLE ...');        // not read at all
```

Difflock does not guess at any of these. It reports what it could not read:

- a name that is not a literal becomes an explicit *unresolved*, and the finding says "a column this analysis could not resolve" rather than inventing one;
- an operation inside an `if`, a loop or a `try` is marked **conditional**, and phrased as *may* rather than *will*;
- a raw `DB::statement()` produces a warning saying part of the file was not analysed.

**Difflock does not reconstruct an expected schema from migrations.** For the reasons above that reconstruction cannot be made reliable, and a diff built on a guess is worse than no diff. Drift is measured against a schema that was actually observed and deliberately recorded.

**Difflock cannot tell you whether a statement locks.** Whether an index build or a column rewrite takes a lock, and for how long, depends on the engine, its version, its configuration and sometimes the row contents. Difflock says an index build reads every row, scales its concern by table size, and stops there. Language like *may* and *depending on the database engine and version* is deliberate.

**Difflock has no view of your query workload.** It cannot tell you whether dropping an index will make anything slower. It tells you the difference between dropping an index and dropping a *constraint*, which it can know.

**SQLite reports less than the others.** Laravel's SQLite grammar emits `varchar` for `string('email', 320)`, so lengths and precisions are genuinely unavailable there, and SQLite records no constraint names. Difflock reports null rather than inventing a value, and the comparison layer treats null as *not comparable* — so no diff ever claims a length changed on a driver that never knew it.

**What Difflock does not claim.** Not "100% safe migrations". Not "zero downtime guaranteed". Not "perfect migration analysis". It is a careful second reader with the schema and the row counts in front of it, and it says so where it is guessing.

## Architecture

```text
src/
├── Console/          Commands, renderers, JSON formatters
├── Contracts/        SchemaInspector, SchemaDiffer, MigrationAnalyzer,
│                     MigrationRule, TableStatistics
├── Database/         Connection-backed table statistics, context assembly
├── Diff/             SchemaDiff, TableDiff, ColumnDiff, IndexDiff,
│                     ForeignKeyDiff, SchemaComparator
├── Migration/        Analyzer, context, findings, report
│   ├── Parser/       Tokenizer-based reader for migration source
│   └── Rules/        The nine built-in rules
├── Protection/       MigrationGuard, ProtectionPolicy, GuardDecision
├── Risk/             RiskLevel, RiskSummary
├── Schema/           DatabaseSchema, Table, Column, Index, ForeignKey,
│                     inspector, snapshot, baseline
└── Support/          Type families, byte formatting
```

The boundaries are enforced by architecture tests, not just intended:

- rules cannot reach the console or the database;
- the diff engine cannot reach any renderer;
- the parser cannot reach `eval`, `include`, or the database;
- protection consumes analysis rather than repeating it.

### What semver covers

Treated as public API from 1.0: the contracts, the value objects (`DatabaseSchema`, `Table`, `Column`, `Index`, `ForeignKey`, the diff objects, `MigrationFinding`, `MigrationReport`), `RiskLevel`, the facade, the configuration keys, and the `--format=json` documents. Breaking any of them needs a major version.

### Room left for later

The architecture supports, without being built for it today: HTML reports, a `difflock:report` command, and a separate `difflock/filament` package for a dashboard. Filament is deliberately not a dependency of this package and will not become one.

## Contributing

```bash
composer install
composer test
```

That runs Rector, Pint, PHPStan at max level, 100% type coverage, and the test suite with a 90% line-coverage floor. See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT. See [LICENSE.md](LICENSE.md).
