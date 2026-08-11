---
name: difflock
description: Use when writing, editing or reviewing a Laravel database migration — before showing it to the user. Checks the migration against the live database for destructive operations, lock risk, cascading deletes and columns that will fail on populated tables. Also use before schema work to check whether the database has already drifted.
---

# Writing safe Laravel migrations with Difflock

You cannot see what the database looks like. Difflock can. A migration that is
correct in isolation — `dropColumn('legacy_token')`, `$table->string('status')` —
is a data-loss incident or a failed deploy depending on facts that exist only in
the database: how many rows the table holds, what is indexed, what points at it.

**Never present a migration to the user without checking it first.** Getting this
wrong is not a style problem; it is how production columns get dropped.

## The loop

```
1. difflock_table_context   →  what am I dealing with?
2. draft the migration       (do not write it yet)
3. difflock_lint_migration with `source`  →  what's wrong with it?
4. fix the draft, repeat 3, until nothing is above `low`
5. write the file
6. show the user, quoting anything that remains
```

**Check the draft with `source` before writing it to disk.** `difflock_lint_migration`
takes the migration code directly, and analyses it against the real database — real
row counts, real indexes — even though the file does not exist yet. Checking after
writing means every intermediate mistake lands in the user's repository first.

```
difflock_lint_migration { "source": "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n..." }
```

Use `path` only for migrations that already exist.

If the MCP tools are unavailable, the same facts come from the CLI:

```bash
php artisan difflock:lint --path=database/migrations/2026_08_11_x.php --realpath
php artisan difflock:explain 2026_08_11_x
```

## Reading a finding

Each carries a **risk** (`safe` → `critical`) and two facts that are not opinions:

- `destructive` — this removes data or structure.
- `reversible` — a `down()` with a body exists. **It does not mean the data comes
  back.** A dropped column's `down()` recreates the column and none of its rows.

`context` holds the facts about that specific occurrence — `82,325 rows`,
`covered by users_email_index`. That is usually the field that decides what to do.

## Rules that most often change what you write

| Finding | What to do instead |
| --- | --- |
| `add-not-null-column` on a table with rows | Add `->nullable()` or `->default(...)`. A NOT NULL column with no default has nothing to put in existing rows and most engines refuse the statement. |
| `drop-column` / `drop-table` | Split it: stop reading the column, deploy, drop it in a later migration. Say plainly that the data does not come back. |
| `foreign-key` with a cascade | Prefer `restrictOnDelete()` or `nullOnDelete()` unless children are worthless without the parent. Cascades run inside the database — no model events, no observers, no soft deletes. |
| `rename-column` | The zero-downtime shape is add / write both / backfill / switch reads / drop. During a rolling deploy the old release is still querying the old name. |
| `unindexed-foreign-key` | Add `$table->index('customer_id')` next to `constrained()`. PostgreSQL indexes neither side automatically; MySQL does. |
| `add-index` on a large table | Consider building it outside the deploy with the engine's concurrent form. |
| `sensitive-column` | Ask before storing it: encryption, retention, and whether it belongs in the database at all. |

## Never do these

- **Do not silence a finding to make the check pass.** Not `--fail-on`, not
  `ignore`, not `--accept`. Those are the user's decisions, not yours. Fix the
  migration or explain why the finding is acceptable and let them choose.
- **Do not run `difflock:migrate` without `--dry-run`** unless the user has asked
  you to migrate. It writes to their database.
- **Do not treat an empty findings list as "safe".** Read `warnings` first — a
  migration that builds table names from config, loops, or calls `DB::statement()`
  is one Difflock could only partly read, and it says so there.
- **Do not report a row count of `null` as zero.** `null` means the engine would
  not say. The distinction is the difference between "nothing to backfill" and "we
  have no idea".

## Explaining a rule

Do not reconstruct what a rule means from its name. Call `difflock_rules` — it
returns each rule's own documentation, and the registered set is configurable, so a
project may have rules that were never part of Difflock.

## Saying it to the user

Lead with what will happen, not with the rule name:

> This drops `users.legacy_token`, which holds 82,325 rows. The data is not
> recoverable from `down()` — that recreates the column empty. Two indexes are
> built on it and go with it.

Then the options. Difflock reports risk, not permission — the decision is theirs.
