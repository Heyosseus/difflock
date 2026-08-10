# Changelog

All notable changes to `difflock` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

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

[Unreleased]: https://github.com/Heyosseus/difflock/commits/main
