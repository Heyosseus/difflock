<?php

declare(strict_types=1);

namespace Difflock\Migration\Rules;

use Difflock\Contracts\MigrationRule;
use Difflock\Migration\Blueprint;
use Difflock\Migration\MigrationContext;
use Difflock\Migration\MigrationFinding;
use Difflock\Migration\Parser\Operation;
use Difflock\Migration\Subject;
use Difflock\Risk\RiskLevel;

/**
 * Columns whose names say they will hold payment data, government identifiers or
 * credentials.
 *
 * The migration is the only moment anybody deliberately thinks about a column's
 * whole life — whether it should be encrypted, how long it may be kept, whether it
 * puts the application in scope for a standard it was not in scope for yesterday.
 * After that it is just a column, and nobody looks again.
 *
 * ## What this rule does and does not claim
 *
 * It reads **names**. It cannot see whether a value is encrypted, hashed, tokenised
 * or truncated, and it says so in every finding. A `card_number` column holding
 * nothing but the last four digits is fine, and this rule will still mention it —
 * that is the intended trade, because the cost of confirming "yes, we thought about
 * it" is a few seconds and the cost of not being asked can be a great deal more.
 *
 * The risk level here is review urgency rather than deployment risk: none of these
 * findings say the migration will fail or lock anything. They say it should not
 * land without somebody having decided.
 *
 * ## Deliberately not flagged
 *
 * `password` and `remember_token` are absent from the list. Every Laravel
 * application has both, the framework hashes one and generates the other, and a
 * rule that fired on the framework's own starter migration would be noise on every
 * install — which is how a security rule teaches people to ignore security rules.
 */
final class SensitiveColumnRule implements MigrationRule
{
    /**
     * Payment data. Card numbers belong to a compliance regime; the verification
     * codes may not be stored after authorisation at all, by any party, encrypted or
     * otherwise.
     *
     * @var array<string, string>
     */
    private const array PAYMENT = [
        'cvv' => 'a card verification code',
        'cvc' => 'a card verification code',
        'card_number' => 'a payment card number',
        'cardnumber' => 'a payment card number',
        'credit_card' => 'payment card data',
        'card_expiry' => 'payment card expiry data',
        'iban' => 'a bank account identifier',
        'sort_code' => 'a bank routing code',
        'account_number' => 'a bank account number',
    ];

    /**
     * Government identifiers — the class of personal data that cannot be reissued
     * when it leaks.
     *
     * @var array<string, string>
     */
    private const array IDENTITY = [
        'ssn' => 'a social security number',
        'social_security' => 'a social security number',
        'national_id' => 'a national identity number',
        'passport_number' => 'a passport number',
        'tax_id' => 'a taxpayer identifier',
        'drivers_license' => 'a driving licence number',
        'date_of_birth' => 'a date of birth',
    ];

    /**
     * Credentials for other systems, which are the ones that turn one breach into
     * several.
     *
     * @var array<string, string>
     */
    private const array CREDENTIAL = [
        'api_key' => 'an API key',
        'api_secret' => 'an API secret',
        'client_secret' => 'a client secret',
        'private_key' => 'a private key',
        'secret_key' => 'a secret key',
        'access_token' => 'an access token',
        'refresh_token' => 'a refresh token',
        'webhook_secret' => 'a webhook secret',
    ];

    public function identifier(): string
    {
        return 'sensitive-column';
    }

    public function analyze(MigrationContext $context): array
    {
        $findings = [];

        foreach ($context->statement->operations as $operation) {
            if (! Blueprint::isColumn($operation->method)) {
                continue;
            }
            if ($operation->hasModifier('change')) {
                continue;
            }
            foreach (Blueprint::columnsOf($operation) as $column) {
                $finding = $this->column($context, $operation, $column);

                if ($finding instanceof MigrationFinding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    private function column(MigrationContext $context, Operation $operation, string $column): ?MigrationFinding
    {
        $normalised = strtolower($column);

        [$kind, $describes, $risk] = match (true) {
            ($describes = $this->match($normalised, self::PAYMENT)) !== null => ['payment', $describes, RiskLevel::High],
            ($describes = $this->match($normalised, self::IDENTITY)) !== null => ['identity', $describes, RiskLevel::Medium],
            ($describes = $this->match($normalised, self::CREDENTIAL)) !== null => ['credential', $describes, RiskLevel::Medium],
            default => [null, null, RiskLevel::Safe],
        };

        if ($kind === null || $describes === null) {
            return null;
        }

        return $context->finding(
            rule: $this->identifier(),
            risk: $risk,
            message: 'SENSITIVE COLUMN '.($context->tableName() ?? '<unresolved>').'.'.$column
                .' looks like it holds '.$describes,
            explanation: $this->explain($kind, $describes),
            suggestion: $this->suggest($kind),
            subject: $column,
            subjectType: Subject::Column,
            reversible: $context->reversible(),
            operation: $operation,
        );
    }

    /**
     * @param  array<string, string>  $patterns
     */
    private function match(string $column, array $patterns): ?string
    {
        foreach ($patterns as $needle => $describes) {
            // Matched as a whole word within the name, so `card_number` and
            // `billing_card_number` both hit while `discard_numbers` does not.
            if (preg_match('/(^|_)'.preg_quote($needle, '/').'($|_)/', $column) === 1) {
                return $describes;
            }
        }

        return null;
    }

    private function explain(string $kind, string $describes): string
    {
        $shared = 'Difflock reads the column name only. It cannot tell whether the value is '
            .'encrypted, hashed, tokenised or truncated, so this is a prompt to confirm rather '
            .'than a claim that anything is wrong.';

        return match ($kind) {
            'payment' => 'This column looks like it will hold '.$describes.'. Storing it may bring the '
                .'application into scope for payment-industry requirements it was not in scope for '
                .'before, and card verification codes may not be retained after authorisation at all. '
                .$shared,
            'identity' => 'This column looks like it will hold '.$describes.'. Identifiers of this kind '
                .'cannot be reissued when they leak, and usually carry retention limits and a duty to '
                .'say who can read them. '.$shared,
            default => 'This column looks like it will hold '.$describes.' for another system. A '
                .'credential in a database column turns one breach into two, and it will be in every '
                .'backup, replica and database export from now on. '.$shared,
        };
    }

    private function suggest(string $kind): string
    {
        return match ($kind) {
            'payment' => 'Prefer storing a token from your payment provider over the number itself, and '
                .'never store the verification code. If you must hold card data, confirm the encryption '
                .'and retention story before this ships.',
            'identity' => 'Consider Laravel\'s `encrypted` cast, decide how long the value may be kept, '
                .'and check it is not about to appear in logs, exports or a database seed.',
            default => 'Consider whether this belongs in the database at all rather than in a secrets '
                .'manager. If it does, use the `encrypted` cast so it is not readable in a backup.',
        };
    }
}
