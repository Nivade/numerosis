<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Billing;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case Canceled = 'canceled';
    case Incomplete = 'incomplete';
    case IncompleteExpired = 'incomplete_expired';
    case PastDue = 'past_due';
    case Paused = 'paused';
    case Trialing = 'trialing';
    case Unpaid = 'unpaid';

    /**
     * Whether to provision or restore a tenant. A trial has paid nothing but
     * still counts, unlike `isPaid()`.
     */
    public function isSettled(): bool
    {
        return match ($this) {
            self::Active, self::Trialing => true,
            default => false,
        };
    }

    public function isDelinquent(): bool
    {
        return match ($this) {
            self::PastDue, self::Unpaid, self::IncompleteExpired => true,
            default => false,
        };
    }

    /**
     * An invoice the customer can still settle. Narrower than `isDelinquent()`:
     * an expired incomplete subscription charged nothing and has nothing to pay.
     *
     * @return list<string>
     */
    public static function unpaidInvoiceValues(): array
    {
        return [self::PastDue->value, self::Unpaid->value];
    }

    /**
     * Deliberately narrower than `isSettled()`. A trial collects nothing
     * upfront, and the unpaid-tenant quota exists to stop free tenant
     * databases; it is not meant to gate on settlement.
     */
    public function isPaid(): bool
    {
        return $this === self::Active;
    }
}
