<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Tenancy;

use Livewire\Wireable;
use Nvade\Numerosis\Enums\BillingCycle;
use Spatie\LaravelData\Concerns\WireableData;
use Spatie\LaravelData\Data;

/**
 * The tenant-registration payload, persisted on the pending-provision row
 * (`PendingTenantProvision`). Stripe metadata only ever carries the domain —
 * see custom-checkout.md, "Consequence: Stripe metadata shrinks".
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
    ) {}
}
