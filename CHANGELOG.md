# Changelog

All notable changes to `difflock` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[0.1.0]: https://github.com/Heyosseus/difflock/releases/tag/v0.1.0
