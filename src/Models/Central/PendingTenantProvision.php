<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvade\Numerosis\Database\Factories\Central\PendingTenantProvisionFactory;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Override;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A tenant that has been claimed but does not exist yet. Written when the
 * checkout session is created (status `reserved`), promoted to `provisioning`
 * once payment is confirmed, and deleted once provisioning succeeds. It exists
 * so `tenants.mine` can render a placeholder before the Tenant row does;
 * afterwards readiness is `tenants.provisioned_at`.
 *
 * @property string $domain
 * @property string|null $custom_domain
 * @property string $company_name
 * @property string $global_id
 * @property string|null $payment_plan
 * @property BillingCycle|null $billing_cycle
 * @property string|null $stripe_setup_intent_id
 * @property string|null $stripe_subscription_id
 * @property TenantProvisionStatus $status
 * @property Carbon|null $failed_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Model
 */
#[WithoutIncrementing]
#[UseFactory(PendingTenantProvisionFactory::class)]
class PendingTenantProvision extends Model
{
    use CentralConnection;

    /** @use HasFactory<PendingTenantProvisionFactory> */
    use HasFactory;

    protected $primaryKey = 'domain';

    protected $keyType = 'string';

    protected $guarded = [];

    public function hasFailed(): bool
    {
        return $this->status === TenantProvisionStatus::Failed;
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'status' => TenantProvisionStatus::class,
            'billing_cycle' => BillingCycle::class,
            'failed_at' => 'datetime',
        ];
    }
}
