<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Nvade\Numerosis\Actions\Queries\GetActiveSubscription;
use Nvade\Numerosis\Actions\Queries\GetBillingPeriod;
use Nvade\Numerosis\Actions\Queries\GetTenantMeters;
use Nvade\Numerosis\Actions\Queries\GetTenantSeatUsage;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\CacheTtl;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Contracts\Billing\Entitlements;
use Nvade\Numerosis\Contracts\Billing\UsageCounter;
use Nvade\Numerosis\Data\Billing\BillingPeriod;
use Nvade\Numerosis\Data\Billing\MeterDefinitionData;
use Nvade\Numerosis\Exceptions\Billing\EntitlementDenied;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

/**
 * Memoized per request, and only as scalars. A cached plan model keyed loosely
 * is how tenant data leaks between tenants.
 */
class PlanEntitlements implements Entitlements
{
    /** @var array<string, array{capabilities: list<string>, limits: array<string, int>, plan: string|null, upgrade: string|null}> */
    private array $resolved = [];

    /** @var array<string, Collection<int, MeterDefinitionData>> */
    private array $meters = [];

    /** @var array<string, string> The period bucket as a date, empty when the tenant has no subscription. */
    private array $buckets = [];

    public function __construct(private readonly UsageCounter $counter) {}

    public function allows(string $capability, ?TenantContract $tenant = null): bool
    {
        $tenant = $this->tenant($tenant);

        if (! $tenant instanceof Tenant) {
            return false;
        }

        // A declared meter is a capability the plan sells by the unit, so the
        // plan need not also list it as a feature slug to allow it.
        return in_array($capability, $this->entitlements($tenant)['capabilities'], true)
            || $this->meter($tenant, $capability) instanceof MeterDefinitionData;
    }

    public function limit(string $capability, ?TenantContract $tenant = null): ?int
    {
        $tenant = $this->tenant($tenant);

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return $this->entitlements($tenant)['limits'][$capability] ?? null;
    }

    public function used(string $capability, ?TenantContract $tenant = null): int
    {
        $tenant = $this->tenant($tenant);

        if (! $tenant instanceof Tenant) {
            return 0;
        }

        // Seats are rows, not events: counting them off the usage table would
        // drift the moment a member was removed anywhere but through the one
        // action that decrements.
        return $capability === self::SEATS
            ? GetTenantSeatUsage::run($tenant)->used()
            : $this->counter->value($tenant, $capability, $this->bucket($tenant, $capability));
    }

    public function remaining(string $capability, ?TenantContract $tenant = null): ?int
    {
        $limit = $this->limit($capability, $tenant);

        if ($limit === null) {
            return null;
        }

        // Never negative: a downgrade is allowed to leave usage above the new
        // limit, and the answer to "how many left" there is none.
        return max(0, $limit - $this->used($capability, $tenant));
    }

    public function consume(string $capability, int $amount = 1, ?TenantContract $tenant = null): int
    {
        $tenant = $this->tenant($tenant);

        if (! $tenant instanceof Tenant) {
            throw EntitlementDenied::notIncluded($capability, null);
        }

        $this->assertAllowed($capability, $tenant);

        $limit = $this->limit($capability, $tenant);
        $used = $this->used($capability, $tenant);

        // A metered capability's included allowance is where the overage
        // starts, not where the customer is cut off: refusing it here would
        // refuse usage the plan sells.
        $metered = $this->meter($tenant, $capability) instanceof MeterDefinitionData;

        if (! $metered && $limit !== null && $used + $amount > $limit) {
            throw EntitlementDenied::exhausted($capability, $limit, $this->entitlements($tenant)['upgrade']);
        }

        return $this->counter->increment($tenant, $capability, $amount, $this->bucket($tenant, $capability));
    }

    public function assertAllowed(string $capability, ?TenantContract $tenant = null): void
    {
        if ($this->allows($capability, $tenant)) {
            return;
        }

        $resolvedTenant = $this->tenant($tenant);

        throw EntitlementDenied::notIncluded(
            $capability,
            $resolvedTenant instanceof Tenant ? $this->entitlements($resolvedTenant)['upgrade'] : null,
        );
    }

    /** The meter a capability is billed through, when the plan declares one. */
    private function meter(Tenant $tenant, string $capability): ?MeterDefinitionData
    {
        $key = (string) $tenant->getTenantKey();

        $meters = $this->meters[$key] ??= GetTenantMeters::run($tenant);

        return $meters->first(fn (MeterDefinitionData $meter): bool => $meter->key === $capability);
    }

    /**
     * A metered counter is per billing period; a quota counter is for the life
     * of the tenant, which is what `null` means to the counter.
     */
    private function bucket(Tenant $tenant, string $capability): ?Carbon
    {
        if (! $this->meter($tenant, $capability) instanceof MeterDefinitionData) {
            return null;
        }

        $key = (string) $tenant->getTenantKey();

        $period = GetBillingPeriod::run($tenant);

        $bucket = $this->buckets[$key] ??= $period instanceof BillingPeriod ? $period->bucket()->toDateString() : '';

        return $bucket === '' ? null : Date::parse($bucket);
    }

    /**
     * @return array{capabilities: list<string>, limits: array<string, int>, plan: string|null, upgrade: string|null}
     */
    private function entitlements(Tenant $tenant): array
    {
        $key = (string) $tenant->getTenantKey();

        return $this->resolved[$key] ??= GlobalCache::remember(
            CacheKeys::entitlements($key),
            CacheTtl::entitlements(),
            fn (): array => $this->resolve($tenant),
        );
    }

    /**
     * @return array{capabilities: list<string>, limits: array<string, int>, plan: string|null, upgrade: string|null}
     */
    private function resolve(Tenant $tenant): array
    {
        $plan = $this->activePlan($tenant);

        if (! $plan instanceof PaymentPlan) {
            return [
                'capabilities' => $this->freeTierCapabilities(),
                'limits' => $this->freeTierLimits(),
                'plan' => null,
                'upgrade' => $this->cheapestPaidPlan(),
            ];
        }

        /** @var list<string> $capabilities */
        $capabilities = $plan->availableFeatures()->pluck('slug')
            ->map(fn (mixed $slug): string => is_scalar($slug) ? (string) $slug : '')
            ->all();

        return [
            'capabilities' => $capabilities,
            'limits' => $this->limitsFrom($plan),
            'plan' => $plan->slug,
            'upgrade' => $this->nextPlanAbove($plan),
        ];
    }

    private function activePlan(Tenant $tenant): ?PaymentPlan
    {
        $subscription = GetActiveSubscription::run($tenant);

        return $subscription instanceof Subscription ? $subscription->paymentPlan : null;
    }

    /**
     * `options.limits.*`, with `options.max_users` kept as the seats limit it
     * already was. Both come from host-editable data, so a non-numeric value
     * is uncapped exactly as an absent one is: a malformed entry must not lock
     * a customer out of what they are paying for.
     *
     * @return array<string, int>
     */
    private function limitsFrom(PaymentPlan $plan): array
    {
        $options = $plan->metadata()['options'] ?? [];
        $limits = [];

        if (is_array($options)) {
            $declared = $options['limits'] ?? [];

            if (is_array($declared)) {
                foreach ($declared as $capability => $value) {
                    if (is_string($capability) && is_numeric($value)) {
                        $limits[$capability] = (int) $value;
                    }
                }
            }

            $maxUsers = $options['max_users'] ?? null;

            if (is_numeric($maxUsers)) {
                $limits[self::SEATS] = (int) $maxUsers;
            }
        }

        // A meter's included allowance reads as its limit, which is what the
        // usage screen compares against; consumption past it is billed.
        foreach (GetTenantMeters::forPlan($plan) as $meter) {
            if ($meter->included !== null) {
                $limits[$meter->key] = $meter->included;
            }
        }

        return $limits;
    }

    /**
     * @return list<string>
     */
    private function freeTierCapabilities(): array
    {
        /** @var list<string> $capabilities */
        $capabilities = array_values(array_filter(
            Config::array('numerosis.billing.free_tier.capabilities', []),
            is_string(...),
        ));

        return $capabilities;
    }

    /**
     * @return array<string, int>
     */
    private function freeTierLimits(): array
    {
        $limits = [];

        foreach (Config::array('numerosis.billing.free_tier.limits', []) as $capability => $value) {
            if (is_string($capability) && is_numeric($value)) {
                $limits[$capability] = (int) $value;
            }
        }

        return $limits;
    }

    private function cheapestPaidPlan(): ?string
    {
        $plan = Numerosis::model(PaymentPlan::class)::query()->available()->orderBy('monthly_price')->first();

        return $plan?->slug;
    }

    /** The cheapest plan that costs more than this one, which is what an upgrade prompt means. */
    private function nextPlanAbove(PaymentPlan $plan): ?string
    {
        $next = Numerosis::model(PaymentPlan::class)::query()
            ->available()
            ->where('monthly_price', '>', $plan->monthly_price)
            ->orderBy('monthly_price')
            ->first();

        return $next?->slug;
    }

    /** Drops the memo so the next read resolves the plan again. Null clears every tenant. */
    public function forget(?TenantContract $tenant = null): void
    {
        if (! $tenant instanceof TenantContract) {
            $this->resolved = [];
            $this->meters = [];
            $this->buckets = [];

            return;
        }

        $key = (string) $tenant->getTenantKey();

        GlobalCache::store()->forget(CacheKeys::entitlements($key));

        unset($this->resolved[$key], $this->meters[$key], $this->buckets[$key]);
    }

    private function tenant(?TenantContract $tenant): ?TenantContract
    {
        $ambient = tenancy()->tenant;

        return $tenant ?? ($ambient instanceof TenantContract ? $ambient : null);
    }
}
