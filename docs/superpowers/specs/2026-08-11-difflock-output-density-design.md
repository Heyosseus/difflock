# Difflock output density

**Date:** 2026-08-11
**Status:** approved, not yet implemented
**Target release:** 0.4.0

## Problem

`difflock:lint` prints 693 lines against a real 170-migration application. Measured
on crococrm at v0.3.0, alongside every other command:

| Command | Lines |
| --- | --- |
| `difflock` | 10 |
| `difflock:diff --save` | 6 |
| `difflock:check --ci` | 8 |
| `difflock:doctor` | 22 |
| **`difflock:lint`** | **693** |

Four of the five are already short. `difflock:lint` is the outlier, and output that
long is not read — it is scrolled past, which makes the findings in it worthless
however correct they are.

### Why it is long

Findings are grouped by rule, risk **and the explanation text**, so that a group can
print its shared prose once. That worked when it was written — a group of 105
identical cascade findings rendered in 15 lines instead of ~950.

It stopped working because several rules embed per-occurrence facts in their
explanations:

- `drop-column` appends `The table holds 82,325 rows.` and names the indexes
  covering the column.
- `add-index`, `add-not-null-column`, `foreign-key` and `large-table` quote each
  table's size.
- `unindexed-foreign-key` quotes the size too.

Every distinct row count produces a distinct explanation, so nearly every finding
becomes a group of one, printing its own paragraph. Adding three rules in 0.2.x
pushed the total from 314 lines to 693.

## Design

### 1. Findings gain a `context` field

`MigrationFinding` gains an optional `context: ?string` — a short phrase describing
*this occurrence*, not the class of problem:

```
82,325 rows
covered by users_email_index
also drops the foreign key constraint
0 reads in 274 days
```

**The invariant this establishes:** an explanation may not embed per-occurrence
data. Table names, column names, row counts, index names and scan counts belong in
`message` (what) or `context` (the specific circumstance), never in `explanation`
(why it matters).

Branch variation in explanations remains legitimate. `add-not-null-column` has three
genuinely different explanations — rows known to be above zero, known to be zero,
and unknown — because those are three different arguments, not three renderings of
one. `change-column` composes its explanation from the concerns it found. These
collapse to a handful of groups, not hundreds.

### 2. The renderer gains two modes

**Summary** — the default for every command that reports findings:

```
  Difflock  ·  Migration Analysis
  ────────────────────────────────────────

  ✗ CRITICAL  14   drop-table, drop-column
  ✗ HIGH     125   foreign-key, rename-column
  ⚠ MEDIUM    37   change-column, large-table
  ⚠ LOW       24   add-index

  Worst:
    DROP COLUMN users.legacy_token
      2026_01_30_...:15
    DROP TABLE activity_log
      2026_02_02_...:9

  170 migrations · 199 findings
  → difflock:lint -v            all findings
  → difflock:lint --rule=X      one rule
  → difflock:report             shareable HTML
```

Bounded at roughly 20 lines regardless of how many findings exist. Levels with a
count of zero are omitted rather than printed as `0`.

**Detailed** (`-v`) — the existing grouped rendering, which now groups as intended:
one paragraph per rule-and-branch, with occurrences listed beneath carrying their
own `context`. Roughly 90 lines on crococrm.

The risk tally, the accepted-findings count, the database-unavailable notice and the
"not fully analysed" warnings appear in **both** modes. They are the things a reader
would not know to ask for, and abbreviating them is how a summary becomes a lie.

### 3. Applied consistently

| Command | Behaviour |
| --- | --- |
| `difflock:lint` | Summary; `-v` for detail |
| `difflock:check` | Summary; `-v` for detail |
| `difflock:check --ci` | Unchanged — already 8 lines |
| `difflock:migrate` | **Blocking findings in full**, everything else collapsed |
| `difflock` | Summary, aligned to the same renderer |

`difflock:migrate` is the exception on purpose: it is the one moment the tool has
stopped somebody from writing to a database, and the findings that caused it are the
entire reason to read the output.

### 4. HTML report

No structural change. It groups on the same key, so invariant explanations tighten it
automatically, and `context` renders on each occurrence row.

### 5. Rules to change

All eleven are reviewed; these move text out of `explanation`:

| Rule | Moves to `context` |
| --- | --- |
| `drop-table` | row count |
| `drop-column` | row count, covering indexes, foreign keys built on it |
| `change-column` | the old and new lengths, the old and new type families, the row count — the concern sentences themselves stay in `explanation`, since they are the argument |
| `add-not-null-column` | row count |
| `add-index` | row count |
| `drop-index` | scan count and window, covered columns |
| `foreign-key` | row count |
| `unindexed-foreign-key` | row count |
| `large-table` | row count, byte size |
| `redundant-index` | covering index name |
| `sensitive-column` | none — already invariant |

## Testing

The load-bearing test, which encodes the design rule rather than a symptom:

> Fire the same rule against two tables of different sizes and assert the two
> findings carry an **identical** `explanation`.

A future rule that bakes a row count back into its prose then fails the build instead
of quietly re-inflating the output.

Supporting tests:

- Summary output stays under 25 lines with 200 findings.
- `-v` prints each rule's paragraph exactly once.
- Summary never omits the tally, the accepted count, the unavailable-database notice
  or the parser warnings.
- `difflock:migrate` shows every blocking finding in full when it blocks.
- Exit codes are unchanged in every mode — verbosity is presentational, exactly as
  the existing filter tests assert for `--rule`.

## Public API impact

- `MigrationFinding` gains an optional constructor parameter. Additive; existing
  named-argument construction is unaffected.
- `toArray()` gains a `context` key. Additive to the documented JSON shape.
- Console output changes shape. Not covered by semver, but a visible change worth a
  changelog entry.

Minor bump: **0.4.0**.

## Explicitly out of scope

- Batched schema introspection. Separate concern, separate spec.
- Any change to which findings are produced, or to their risk levels. This spec
  changes how findings are *presented* and where their text lives, and nothing else.
