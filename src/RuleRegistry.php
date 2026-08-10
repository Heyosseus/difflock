<?php

declare(strict_types=1);

namespace Difflock;

use Difflock\Contracts\MigrationRule;
use Illuminate\Contracts\Container\Container;

/**
 * The rules the analyzer will run, from configuration and from the application.
 *
 * Rules are held as class names and resolved through the container when an analysis
 * starts, which means a custom rule can take constructor dependencies like anything
 * else, and means registration order does not matter — a rule added in a service
 * provider's `boot()` is picked up by a command that resolves later.
 *
 * Duplicates are collapsed by identifier, last one winning. That is what lets an
 * application replace a built-in rule with its own stricter version by registering a
 * rule that answers to the same identifier, without having to edit the configured
 * list to remove the original.
 */
final class RuleRegistry
{
    /**
     * @param  list<class-string<MigrationRule>|MigrationRule>  $rules
     */
    public function __construct(private array $rules = []) {}

    /**
     * @param  class-string<MigrationRule>|MigrationRule  $rule
     */
    public function add(string|MigrationRule $rule): self
    {
        $this->rules[] = $rule;

        return $this;
    }

    /**
     * The registered rules, resolved and de-duplicated.
     *
     * A configured class that is not a {@see MigrationRule} is skipped rather than
     * fatal: a typo in `config/difflock.php` should not take down every Artisan
     * command in the application.
     *
     * @return list<MigrationRule>
     */
    public function resolve(Container $container): array
    {
        $resolved = [];

        foreach ($this->rules as $rule) {
            $instance = is_string($rule) ? $this->instantiate($container, $rule) : $rule;

            if ($instance instanceof MigrationRule) {
                $resolved[$instance->identifier()] = $instance;
            }
        }

        return array_values($resolved);
    }

    private function instantiate(Container $container, string $rule): ?object
    {
        if (! class_exists($rule)) {
            return null;
        }

        $instance = $container->make($rule);

        return is_object($instance) ? $instance : null;
    }
}
