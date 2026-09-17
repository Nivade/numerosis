<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing;

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Queries\GetTenantSeatUsage;
use Nvade\Numerosis\Contracts\Billing\Entitlements;
use Nvade\Numerosis\Contracts\Billing\UsageCounter;
use Nvade\Numerosis\Exceptions\Billing\EntitlementDenied;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

/**
 * Reads the plan the tenant is actually on, falling back to the free tier in
 * `numerosis.billing.free_tier`. A tenant without an active subscription is
 * the normal state during a trial and during dunning, so nothing here throws
 * for the absence of one.
 *
 * Memoized per request and **only as scalars**: a cached plan model keyed
 * loosely is how tenant data leaks between tenants.
 */
class PlanEntitlements implements Entitlements
{
    public const string SEATS = 'seats';

    /** @var array<string, array{capabilities: list<string>, limits: array<string, int>, plan: string|null, upgrade: string|null}> */
    private array $resolved = [];

    public function __construct(private readonly UsageCounter $counter) {}

    public function allows(string $capability, ?TenantContract $tenant = null): bool
    {
        $tenant = $this->tenant($tenant);

        if (! $tenant instanceof Tenant) {
            return false;
        }

        return in_array($capability, $this->entitlements($tenant)['capabilities'], true);
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
            : $this->counter->value($tenant, $capability);
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

        if ($limit !== null && $used + $amount > $limit) {
            throw EntitlementDenied::exhausted($capability, $limit, $this->entitlements($tenant)['upgrade']);
        }

        return $this->counter->increment($tenant, $capability, $amount);
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

    /**
     * @return array{capabilities: list<string>, limits: array<string, int>, plan: string|null, upgrade: string|null}
     */
    private function entitlements(Tenant $tenant): array
    {
        $key = (string) $tenant->getTenantKey();

        return $this->resolved[$key] ??= $this->resolve($tenant);
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
        $subscription = $tenant->subscriptions()->get()
            ->first(fn (Subscription $subscription): bool => $subscription->valid());

        return $subscription?->paymentPlan;
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

    private function tenant(?TenantContract $tenant): ?TenantContract
    {
        $ambient = tenancy()->tenant;

        return $tenant ?? ($ambient instanceof TenantContract ? $ambient : null);
    }
}
