<?php

declare(strict_types=1);

namespace Difflock\Mcp\Tools;

use Difflock\Contracts\MigrationRule;
use Difflock\Mcp\Tool;
use Difflock\RuleRegistry;
use Illuminate\Contracts\Container\Container;
use ReflectionClass;
use ReflectionException;

/**
 * "What does this rule actually check, and what is it for?"
 *
 * Findings name a rule — `unindexed-foreign-key`, `add-not-null-column` — and an
 * agent asked to explain one has two options: reconstruct the reasoning from the
 * name, or ask. The first produces confident text that is sometimes wrong, which is
 * the exact failure mode this package spends most of its effort avoiding.
 *
 * So the reasoning is published. Each rule's own documentation — the paragraph its
 * author wrote about when it fires and why it matters — is returned verbatim, along
 * with which rules an application has registered, since that set is configurable and
 * may include rules Difflock never shipped.
 */
final readonly class Rules implements Tool
{
    public function __construct(
        private RuleRegistry $registry,
        private Container $container,
    ) {}

    public function name(): string
    {
        return 'difflock_rules';
    }

    public function description(): string
    {
        return 'List the migration rules this application has registered, with what each one checks '
            .'and why it matters. Call this when explaining a finding to the user, rather than '
            .'inferring what a rule means from its name — the set is configurable and may include '
            .'rules specific to this codebase.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'rule' => [
                    'type' => 'string',
                    'description' => 'A single rule identifier, such as unindexed-foreign-key. '
                        .'Omit to list them all.',
                ],
            ],
            'required' => [],
        ];
    }

    public function handle(array $arguments): array
    {
        $wanted = $arguments['rule'] ?? null;
        $wanted = is_string($wanted) && $wanted !== '' ? $wanted : null;

        $rules = [];

        foreach ($this->registry->resolve($this->container) as $rule) {
            if ($wanted !== null && $rule->identifier() !== $wanted) {
                continue;
            }

            $rules[] = [
                'rule' => $rule->identifier(),
                'class' => $rule::class,
                'built_in' => str_starts_with($rule::class, 'Difflock\\Migration\\Rules\\'),
                'explains' => $this->documentation($rule),
            ];
        }

        if ($wanted !== null && $rules === []) {
            return [
                'error' => 'No rule called '.$wanted.' is registered.',
                'next' => 'Call this tool with no arguments to see which rules this application has.',
            ];
        }

        return ['rules' => $rules];
    }

    /**
     * The rule's own class documentation, as prose.
     *
     * Read from the docblock rather than duplicated into a table, so it cannot drift
     * away from the rule it describes: changing a rule's reasoning changes what an
     * agent is told about it, with nothing to keep in step.
     */
    private function documentation(MigrationRule $rule): string
    {
        try {
            $comment = (new ReflectionClass($rule))->getDocComment();
        } catch (ReflectionException) {
            return '';
        }

        if ($comment === false) {
            return '';
        }

        $lines = [];

        foreach (explode("\n", $comment) as $line) {
            $line = trim($line);
            $line = preg_replace('#^/\*\*+|^\*+/?|\*/$#', '', $line);
            $line = is_string($line) ? trim($line) : '';

            // Annotations are for the reader of the source, not for an agent asking
            // what the rule is about.
            if (str_starts_with($line, '@')) {
                continue;
            }

            $lines[] = $line;
        }

        return trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)) ?? '');
    }
}
