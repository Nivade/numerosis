<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Contracts\Tenancy\PersistsToProvisionColumns;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionContribution;
use Nvade\Numerosis\Database\Factories\Central\TenantProvisionFactory;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Override;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * The record of one tenant being provisioned, from the moment its slug is
 * claimed at checkout (`reserved`) through to `completed`.
 *
 * It outlives the provision: `completed_at` is stamped rather than the row
 * deleted. Tenant readiness is still `tenants.provisioned_at`, never a row
 * here.
 *
 * `step_records` is not yet written by anything.
 *
 * @property string $slug
 * @property string|null $custom_domain
 * @property string $name
 * @property string $global_id
 * @property string|null $payment_plan
 * @property BillingCycle|null $billing_cycle
 * @property string|null $stripe_setup_intent_id
 * @property string|null $stripe_subscription_id
 * @property string|null $stripe_customer_id
 * @property string|null $central_user_id
 * @property TenantProvisionStatus $status
 * @property Carbon|null $settled_at
 * @property Carbon|null $provisioning_started_at
 * @property Carbon|null $completed_at
 * @property array<string, array{outcome: string, at: string}> $step_records
 * @property array<string, array<string, mixed>> $contributions
 * @property Carbon|null $failed_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Model
 */
#[WithoutIncrementing]
#[UseFactory(TenantProvisionFactory::class)]
class TenantProvision extends Model
{
    use CentralConnection;

    /** @use HasFactory<TenantProvisionFactory> */
    use HasFactory;

    protected $primaryKey = 'slug';

    protected $keyType = 'string';

    protected $guarded = [];

    public function hasFailed(): bool
    {
        return $this->status === TenantProvisionStatus::Failed;
    }

    public function isSettled(): bool
    {
        return $this->settled_at !== null;
    }

    /**
     * @template TContribution of ProvisionContribution
     *
     * @param  class-string<TContribution>  $contribution
     * @return TContribution|null
     */
    public function contribution(string $contribution): ?ProvisionContribution
    {
        if (is_a($contribution, PersistsToProvisionColumns::class, true)) {
            /** @var TContribution|null */
            return $contribution::fromProvision($this);
        }

        $stored = $this->contributions[$contribution] ?? null;

        return is_array($stored) ? $contribution::from($stored) : null;
    }

    /**
     * Every contribution the row carries: the column-backed ones named by
     * `numerosis.tenancy.provisioning.contributions`, plus every entry in the
     * JSON blob, which is self-describing because it is keyed by class.
     *
     * A registry is needed only for the column-backed half — nothing about a
     * set of columns says which contribution owns them.
     *
     * @return list<ProvisionContribution>
     */
    public function allContributions(): array
    {
        /** @var list<class-string<ProvisionContribution>> $registered */
        $registered = Config::array('numerosis.tenancy.provisioning.contributions', []);

        $contributions = [];

        foreach ($registered as $contribution) {
            $contributions[] = $this->contribution($contribution);
        }

        foreach (array_keys($this->contributions ?? []) as $stored) {
            if (! is_string($stored) || ! is_a($stored, ProvisionContribution::class, true)) {
                continue;
            }

            $contributions[] = $this->contribution($stored);
        }

        return array_values(array_filter($contributions));
    }

    /**
     * Writes contributions onto the row: column-backed ones into their own
     * columns, the rest into the JSON blob under their class name. Merged
     * rather than replaced, so a later stage contributing billing data does
     * not drop what registration contributed.
     *
     * @param  list<ProvisionContribution>  $contributions
     */
    public function applyContributions(array $contributions): void
    {
        $columns = [];
        $blob = $this->contributions ?? [];

        foreach ($contributions as $contribution) {
            if ($contribution instanceof PersistsToProvisionColumns) {
                $columns = [...$columns, ...array_filter(
                    $contribution->toProvisionColumns(),
                    static fn (mixed $value): bool => $value !== null,
                )];

                continue;
            }

            $blob[$contribution::class] = $contribution->toArray();
        }

        $this->fill([...$columns, 'contributions' => $blob]);
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'status' => TenantProvisionStatus::class,
            'billing_cycle' => BillingCycle::class,
            'settled_at' => 'datetime',
            'provisioning_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'step_records' => 'array',
            'contributions' => 'array',
        ];
    }
}
