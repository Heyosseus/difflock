# Changelog

All notable changes to `difflock` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.4.0 - 2026-08-11

Output density. `difflock:lint` printed **693 lines** against a real 170-migration
application, and output that long is not read — it is scrolled past, which makes the
findings in it worth nothing however correct they are.

### Changed

- **`difflock:lint` summarises by default.** A count per risk level with the rules contributing to each, the worst few findings, and where to find the rest. Its length does not depend on how many findings there are.
- **`-v` shows every finding**, grouped by rule so a shared explanation is printed once for the whole group rather than once per finding.
- **Rules keep per-occurrence facts out of their explanations.** Row counts, index names, dependent foreign keys and index read counts moved to a new `context` field on the finding. This is what makes the grouping work: when `drop-column` appended each table's row count to its paragraph, every finding became a group of one.

### Added

- `MigrationFinding` gains an optional `context` — a short phrase about *this* occurrence, such as `82,325 rows` or `covered by users_email_index`. Additive to the constructor and to the `--format=json` document.

### Note

The risk tally, the accepted-findings count, the unreachable-database notice and the
parser warnings appear in **both** modes. Abbreviating those is how a summary becomes
a lie, so they are never dropped.

## 0.3.0 - 2026-08-11

### Added

- **`difflock:report`** writes the whole run — drift and findings — to a self-contained HTML file, for pull requests and CI artifacts. No external stylesheet, font or script, because an artifact opened from a `file://` URL has no network. Everything in it is escaped: table and column names come from your database, not from this package. `--format=json` writes the same document `difflock:check` emits.
- **Secret-shaped column defaults are flagged before they reach git.** `difflock:diff --save` recognises published credential prefixes (`sk_live_`, `ghp_`, `AKIA`, `AIza`, `-----BEGIN`, …), URLs carrying `user:pass@`, `password=`-style connection strings, and long generated-looking values. It warns and never blocks — these are shapes, not certainties — and is tuned to stay silent on ordinary defaults so the warning keeps its meaning.
- **Progress feedback** while the schema is read, on a decorated terminal only. A CI log has no cursor and a JSON document must not gain a line.

## 0.2.1 - 2026-08-11

### Added

- **`unindexed-foreign-key`** catches the most common Laravel/PostgreSQL performance bug: PostgreSQL creates no index for the column a foreign key points *from*, and MySQL does. Engine-aware — silent where the engine handles it, and it names the engine it is actually talking about.
- **`redundant-index`** flags an index a longer one already covers. Only the leading-prefix case, because only that one is certain.
- **`drop-index` now judges on evidence rather than hedging.** It reads the engine's own counters — `pg_stat_user_indexes` on PostgreSQL, `performance_schema` on MySQL — so an index nothing has read in 274 days is reported at low and one serving 2.1M reads at high. The window is quoted with every number, and the caveats (counters are per instance, a short window proves nothing) are repeated rather than rounded off. Read counts can never soften a dropped *constraint*.

### Changed

- A check now reads the schema **once** rather than once for drift and again for the rules — measured at 598 queries and 3.7s on a 99-table PostgreSQL database before the change. Locked in by a regression test rather than a one-off measurement.

## 0.2.0 - 2026-08-11

### Added

- **`difflock:doctor`** reports what Difflock can see: connection, driver, version, reachability, table and migration counts, registered rules, and where the baseline and accepted-findings files live. Its central line is **privileges** — it opens a transaction, attempts the cheapest possible write and rolls back, then says whether the role Difflock connects as *could* write. That turns "Difflock never writes" from a claim about code into something checkable about your role.
- **`sensitive-column`** flags columns whose names suggest payment data, government identifiers or credentials. Deliberately silent on `password` and `remember_token`, which every Laravel application has — a rule that fires on the framework's own starter migration teaches people to ignore security rules.
- **Dropped audit trails** are called out in `drop-table`. A sentence, never a level, so a false positive costs prose rather than a blocked deploy.

### Changed

- The overview command no longer reprints the entire findings list.

## 0.1.0 - 2026-08-10

First release. Deliberately `0.x`: the public API is documented and tested, but
nothing has yet depended on it in anger, and the risk levels the rules return are
the part most likely to want tuning once they have met more codebases. Expect the
contracts to settle before 1.0 rather than after it.

### Requirements

- PHP 8.3+, Laravel 12 or 13. Laravel 11 is not supported: every one of its releases is covered by a security advisory, so Composer's default policy refuses to install them.

### Added

- **Schema introspection** across MySQL, MariaDB, PostgreSQL and SQLite through Laravel's own schema builder — no Doctrine DBAL. Normalised, deterministic value objects for tables, columns, indexes and foreign keys, with every field a driver did not report left as `null` rather than invented.
- **Schema diff** (`difflock:diff`) against a recorded, committable baseline or between two connections. Reports tables, columns, indexes and foreign keys added, removed and changed, comparing only the fields both sides actually reported.
- **Migration linting** (`difflock:lint`) built on a tokenizer-based reader of migration source that never loads or executes a migration class. Reports what it could not resolve — non-literal names, conditional blocks, raw SQL — instead of reporting nothing.
- **Nine built-in rules**: `drop-table`, `drop-column`, `rename-column`, `change-column`, `add-not-null-column`, `add-index`, `drop-index`, `foreign-key`, `large-table`.
- **A deterministic risk model** — `Safe`, `Low`, `Medium`, `High`, `Critical` — with `destructive` and `reversible` reported as facts alongside it.
- **Table-size context** from cheap database metadata, with unknown treated as its own answer rather than as zero.
- **Migration protection** (`difflock:migrate`), opt-in and hooking nothing globally, with `--dry-run` and `--allow-risky`.
- **CI mode** (`difflock:check --ci`) with documented exit codes: 0 clean, 1 findings or drift, 2 the check did not run.
- **JSON output** on every command, versioned, documented, and free of ANSI.
- **An extensible rule system** — `Difflock::rule()` or the `rules` config array — with rules resolved through the container and keyed by identifier, so a custom rule can replace a built-in.
- **A programmatic API** through the `Difflock` facade or the injectable contracts.

### Adoption

- **An accepted-findings file** (`difflock:lint --accept`). Pointing Difflock at a mature codebase surfaces every risky migration ever written — on a real 170-migration application, 199 findings. Recording them lets the gate fail only on findings that are *new*, while the backlog stays counted in every report. Findings are matched on rule, migration, table and subject, never on line numbers or wording, so reformatting a file does not resurrect them.
- **An empty pending scope audits every migration** instead of printing nothing, and says that is what it did.

### Console

- **Findings are grouped by rule, risk and the explanation they carry**, so shared prose is printed once rather than once per finding. A group of 105 identical cascade findings renders in 15 lines instead of roughly 950.
- **`--rule`, `--table` and `--risk` filters**, with a short preview per group expandable via `-v`. Filtering is presentational and cannot lower the exit code.

### Security

- **`sensitive-column`** flags columns whose names suggest payment data, government identifiers or credentials — and stays deliberately silent on `password` and `remember_token`, which every Laravel application has.
- **Dropped audit trails** are called out in `drop-table`.
- **`ignore.tables` excludes a table from schema inspection entirely**, so it reaches no diff, no report and no committed baseline.
- **`snapshot.defaults` and `snapshot.comments`** control what the committed baseline records. Comments default to off: they are never compared, so recording them was disclosure without benefit.

### Performance

- **`unindexed-foreign-key`** catches the most common Laravel/PostgreSQL performance bug — PostgreSQL creates no index for the column a foreign key points from, and MySQL does. The rule is engine-aware and says nothing where the engine handles it.

[0.4.0]: https://github.com/Heyosseus/difflock/releases/tag/v0.4.0
[0.3.0]: https://github.com/Heyosseus/difflock/releases/tag/v0.3.0
[0.2.1]: https://github.com/Heyosseus/difflock/releases/tag/v0.2.1
[0.2.0]: https://github.com/Heyosseus/difflock/releases/tag/v0.2.0
[0.1.0]: https://github.com/Heyosseus/difflock/releases/tag/v0.1.0
