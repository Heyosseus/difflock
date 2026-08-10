# Contributing

Thanks for considering a contribution.

## Getting set up

```bash
git clone https://github.com/Heyosseus/difflock
cd difflock
composer install
composer test
```

`composer test` runs everything CI runs:

| Step | What it checks |
| --- | --- |
| `composer test:refacto` | Rector, in dry-run |
| `composer test:lint` | Laravel Pint |
| `composer test:types` | PHPStan at `level: max` with Larastan |
| `composer test:type-coverage` | 100% type coverage — every parameter, return and property |
| `composer test:unit` | Pest, with a 90% line-coverage floor |

The unit suite runs against SQLite in memory and needs nothing installed.

## Testing against real drivers

The driver tests in `tests/Integration` skip themselves unless `DIFFLOCK_DB_DRIVER` is set. They are what proves the things that genuinely differ per driver — reported lengths, `unsigned`, foreign key names, the row-count query.

```bash
docker run -d --name difflock-pg -p 5432:5432 \
  -e POSTGRES_DB=difflock -e POSTGRES_USER=difflock -e POSTGRES_PASSWORD=secret postgres:17

DIFFLOCK_DB_DRIVER=pgsql vendor/bin/pest tests/Integration
```

Swap `postgres:17` for `mysql:8.4` or `mariadb:11` (port 3306, `MYSQL_*` env vars) for the others.

## Adding a rule

A rule implements `Difflock\Contracts\MigrationRule` and lives in `src/Migration/Rules`. Register it in `config/difflock.php`, and add its identifier to the table in the README.

Three things a rule must get right, and they are what review will look at:

1. **It says what it does not know.** A row count of `null` means unknown, never zero. A column name that was not a literal is `Unresolved`, and the finding must not invent one.
2. **It does not claim engine behaviour.** Whether a statement locks depends on the engine, the version and the configuration. Say *may*, and say what it depends on.
3. **`destructive` and `reversible` are facts, not opinions.** `reversible` means `down()` has a body — never that the data comes back.

Rules must not reach the console or the database. Both are enforced by architecture tests in `tests/ArchTest.php`, which will fail the build rather than let the boundary erode.

Every rule needs tests for each risk level it can return, including the unknown-row-count path.

## Style

- `declare(strict_types=1)` everywhere.
- Classes are `final` unless there is a stated reason not to be.
- Value objects are `readonly`.
- Pint and Rector decide formatting; run them rather than arguing with them.
- Comments explain *why*, not *what*. If a branch exists because a driver behaves oddly, say which driver and how.

## Public API

The contracts, value objects, `RiskLevel`, findings, the facade, the configuration keys and the `--format=json` documents are public API. Changing any of them incompatibly needs a major version, so a pull request that does should say so.

## Reporting a bug

A migration snippet that reproduces it is worth more than anything else. If it involves a specific driver, say which and which version.
