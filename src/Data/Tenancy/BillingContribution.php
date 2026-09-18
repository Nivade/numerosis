<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Tenancy;

use Nvade\Numerosis\Contracts\Tenancy\PersistsToProvisionColumns;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Override;
use Spatie\LaravelData\Data;

/**
 * What checkout knows about a provision. Absent entirely when a tenant is
 * provisioned without billing, which is what makes `LinkTenantSubscription`
 * skippable instead of conditional on a field the pipeline has to know about.
 */
final class BillingContribution extends Data implements PersistsToProvisionColumns
{
    public function __construct(
        public ?string $payment_plan = null,
        public ?BillingCycle $billing_cycle = null,
        public ?string $promotion_code = null,
        public ?string $stripe_setup_intent_id = null,
        public ?string $stripe_subscription_id = null,
        public ?string $stripe_customer_id = null,
        public ?string $central_user_id = null,
    ) {}

    #[Override]
    public static function fromProvision(TenantProvision $provision): ?static
    {
        $contribution = new self(
            payment_plan: $provision->payment_plan,
            billing_cycle: $provision->billing_cycle,
            promotion_code: $provision->promotion_code,
            stripe_setup_intent_id: $provision->stripe_setup_intent_id,
            stripe_subscription_id: $provision->stripe_subscription_id,
            stripe_customer_id: $provision->stripe_customer_id,
            central_user_id: $provision->central_user_id,
        );

        return $contribution->isEmpty() ? null : $contribution;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toProvisionColumns(): array
    {
        return [
            'payment_plan' => $this->payment_plan,
            'billing_cycle' => $this->billing_cycle,
            'promotion_code' => $this->promotion_code,
            'stripe_setup_intent_id' => $this->stripe_setup_intent_id,
            'stripe_subscription_id' => $this->stripe_subscription_id,
            'stripe_customer_id' => $this->stripe_customer_id,
            'central_user_id' => $this->central_user_id,
        ];
    }

    /**
     * A row with no billing columns set reads back as "no contribution"
     * instead of a contribution full of nulls, since otherwise every step
     * consuming this would run against a tenant provisioned without billing.
     */
    public function isEmpty(): bool
    {
        return array_filter($this->toProvisionColumns(), static fn (mixed $v): bool => $v !== null) === [];
    }
}
