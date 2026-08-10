# Security Policy

## Reporting a vulnerability

Email **ratiruxadzee@gmail.com** rather than opening a public issue. You should get a reply within a few days.

Please include what you were running Difflock against, what happened, and how to reproduce it.

## Supported versions

The latest minor release of the current major receives security fixes.

## What Difflock does and does not touch

Worth stating plainly, because Difflock lives next to destructive operations:

- **It never writes to the database it inspects.** Schema introspection and table-size metadata are reads. There is no code path in the package that issues a write against the inspected connection.
- **It never executes a migration to analyse it.** Migration source is read with PHP's tokenizer; migration classes are never loaded or run. A linter that boots the code it is linting is a linter that can be made to drop your tables.
- **It never hooks `php artisan migrate`.** Installing Difflock does not change when or whether your migrations run. `difflock:migrate` is opt-in and calls Laravel's own migrator only after deciding the pending migrations are safe.
- **`--dry-run` has no write path at all.**
- **It writes exactly one file**, and only when you ask: the schema baseline, at the path you configure, via `difflock:diff --save`.

A read-only database role is enough for everything except `difflock:migrate`, and pointing Difflock at one is the cheapest guarantee available.

## Findings are not secrets, but reports may be

A `--format=json` report contains table names, column names, index names and row counts. That is schema information about your production database. Treat CI artifacts containing it the way you would treat any other description of your data model.
