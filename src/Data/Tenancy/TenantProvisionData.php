<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Tenancy;

use Livewire\Wireable;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Spatie\LaravelData\Concerns\WireableData;
use Spatie\LaravelData\Data;

/**
 * What a tenant is to be provisioned with. One type across both stages of the
 * flow: the checkout gateways receive it before Stripe knows anything, and the
 * provisioning pipeline receives the same shape afterwards with the Stripe
 * fields filled in. Stripe metadata carries only the slug, which is enough to
 * read the rest back off the provision row.
 */
class TenantProvisionData extends Data implements Wireable
{
    use WireableData;

    public function __construct(
        // Becomes tenants.id and the subdomain label, never a domain itself.
        public string $slug,
        public string $name,
        public string $global_id,
        public ?string $payment_plan = null,
        public ?BillingCycle $billing_cycle = null,
        // Only set under IdentificationMode::CustomDomain: the tenant's own
        // fully-qualified domain, as opposed to `$slug` above.
        public ?string $custom_domain = null,
        public ?string $stripeCustomerId = null,
        public ?string $stripeSubscriptionId = null,
        public ?string $centralUserId = null,
    ) {}

    /**
     * Read back off the row the slug in Stripe's metadata resolves to. The
     * Stripe fields are left unset: the caller holding the webhook payload
     * knows them, and the row does not carry the customer id at all.
     */
    public static function fromProvision(TenantProvision $provision): self
    {
        return new self(
            slug: $provision->slug,
            name: $provision->name,
            global_id: $provision->global_id,
            payment_plan: $provision->payment_plan,
            billing_cycle: $provision->billing_cycle,
            custom_domain: $provision->custom_domain,
        );
    }

    /**
     * @return self The same registration with the Stripe identifiers attached.
     */
    public function withStripe(
        ?string $customerId = null,
        ?string $subscriptionId = null,
        ?string $centralUserId = null,
    ): self {
        return new self(
            slug: $this->slug,
            name: $this->name,
            global_id: $this->global_id,
            payment_plan: $this->payment_plan,
            billing_cycle: $this->billing_cycle,
            custom_domain: $this->custom_domain,
            stripeCustomerId: $customerId,
            stripeSubscriptionId: $subscriptionId,
            centralUserId: $centralUserId,
        );
    }

    /**
     * Only `name`: it's validated identically wherever it's collected.
     * `slug`/`custom_domain` are deliberately not here — the registration
     * wizard checks availability against `tenant_provisions` before checkout
     * exists, while checkout checks it through
     * {@see \Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy} against the
     * tenant that's about to be created; same field, different rules by design.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
