<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Tenancy;

use Livewire\Wireable;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Spatie\LaravelData\Concerns\WireableData;
use Spatie\LaravelData\Data;

/**
 * What the user asked for at registration, persisted on the pending-provision
 * row. Stripe metadata carries only the domain, which is enough to find this.
 */
class TenantRegistrationData extends Data implements Wireable
{
    use WireableData;

    public function __construct(
        public string $company_name,
        public string $domain,
        public string $global_id,
        public ?string $payment_plan = null,
        public ?BillingCycle $billing_cycle = null,
        // Only set under IdentificationMode::CustomDomain: the tenant's own
        // fully-qualified domain, distinct from `$domain` above (which
        // always stays the safe id/slug; see CreateTenantDomain).
        public ?string $custom_domain = null,
    ) {}

    /**
     * Only `company_name`: it's validated identically wherever it's
     * collected. `domain`/`custom_domain` are deliberately not here — the
     * registration wizard checks availability against
     * `pending_tenant_provisions` before checkout exists, while checkout
     * checks it through {@see \Nvade\Numerosis\Contracts\Tenancy\TenantDomainPolicy}
     * against the tenant that's about to be created; same field, different
     * rules by design.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
        ];
    }
}
