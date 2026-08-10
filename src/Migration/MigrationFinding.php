<?php

declare(strict_types=1);

namespace Difflock\Migration;

use Difflock\Risk\RiskLevel;

/**
 * One thing a rule wants you to know about a migration before it runs.
 *
 * A finding carries four separate pieces of information, and keeping them separate
 * is deliberate:
 *
 *   - the **message** says what the operation is, in one line;
 *   - the **explanation** says why it matters, hedged where the truth is hedged;
 *   - the **suggestion** says what to do instead, when there is something to do;
 *   - **destructive** and **reversible** are facts about the operation itself, not
 *     opinions about it, and they are what a reviewer scans for first.
 *
 * `reversible` means the migration has a `down()` with a body that could plausibly
 * undo this. It does **not** mean the data comes back: a dropped column's `down()`
 * recreates the column and not one row of what was in it. Rules that drop data set
 * `destructive` and say so in the explanation, whatever `down()` looks like.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
final readonly class MigrationFinding
{
    /**
     * @param  string  $rule  The rule's identifier, kebab-cased: `drop-column`.
     * @param  string|null  $subject  The column, index or constraint the finding is about.
     * @param  bool  $conditional  Whether the operation sits inside an `if` or a loop, so it
     *                             may not run at all. The message is phrased accordingly.
     */
    public function __construct(
        public string $rule,
        public RiskLevel $risk,
        public string $migration,
        public string $message,
        public string $explanation,
        public ?string $suggestion = null,
        public ?string $table = null,
        public ?string $subject = null,
        public Subject $subjectType = Subject::None,
        public bool $destructive = false,
        public bool $reversible = true,
        public ?int $line = null,
        public bool $conditional = false,
    ) {}

    /**
     * The finding as it appears in `--format=json`.
     *
     * The shape is documented and stable: `rule`, `risk`, `table`, `destructive` and
     * `reversible` are always present, and the subject appears under a key naming
     * what it is — `column`, `index` or `constraint` — so a consumer can tell a
     * dropped index from a dropped column without parsing prose.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $report = [
            'rule' => $this->rule,
            'risk' => $this->risk->value,
            'migration' => $this->migration,
            'table' => $this->table,
            'message' => $this->message,
            'explanation' => $this->explanation,
            'suggestion' => $this->suggestion,
            'destructive' => $this->destructive,
            'reversible' => $this->reversible,
            'conditional' => $this->conditional,
            'line' => $this->line,
        ];

        if ($this->subject !== null && $this->subjectType !== Subject::None) {
            $report[$this->subjectType->value] = $this->subject;
        }

        return $report;
    }
}
