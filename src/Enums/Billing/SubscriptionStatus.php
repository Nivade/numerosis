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
     * Deliberately narrower than `isSettled()` — a trial collects nothing
     * upfront, and the unpaid-tenant quota exists to stop free tenant
     * databases, not to gate on settlement.
     */
    public function isPaid(): bool
    {
        return $this === self::Active;
    }
}
