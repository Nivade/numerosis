<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Exceptions\Billing;

use Nvade\Numerosis\Exceptions\DomainException;

/**
 * Carries the capability and, when one exists, the plan that would allow it,
 * so an upgrade prompt is written once rather than at each call site.
 */
class EntitlementDenied extends DomainException
{
    private function __construct(
        string $message,
        public readonly string $capability,
        public readonly ?string $upgradeToPlan = null,
    ) {
        parent::__construct($message);
    }

    public static function notIncluded(string $capability, ?string $upgradeToPlan): self
    {
        return new self(
            $upgradeToPlan === null
                ? "Your plan does not include {$capability}."
                : "Your plan does not include {$capability}. The {$upgradeToPlan} plan does.",
            $capability,
            $upgradeToPlan,
        );
    }

    public static function exhausted(string $capability, int $limit, ?string $upgradeToPlan): self
    {
        return new self(
            $upgradeToPlan === null
                ? "You have used all {$limit} of your {$capability} allowance."
                : "You have used all {$limit} of your {$capability} allowance. The {$upgradeToPlan} plan allows more.",
            $capability,
            $upgradeToPlan,
        );
    }
}
