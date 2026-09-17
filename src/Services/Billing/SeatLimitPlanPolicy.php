<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing;

use Illuminate\Validation\ValidationException;
use Nvade\Numerosis\Actions\Queries\GetTenantSeatUsage;
use Nvade\Numerosis\Contracts\Billing\Entitlements;
use Nvade\Numerosis\Contracts\Billing\Plan;
use Nvade\Numerosis\Contracts\Billing\PlanPolicy;
use Nvade\Numerosis\Contracts\Billing\SeatPolicy;
use Nvade\Numerosis\Contracts\Subscribable;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * `options.max_users` comes from host-editable config, so a non-numeric value
 * counts as no limit, the same as an absent one. A malformed entry must not
 * lock a customer out of a plan they are paying for.
 *
 * @see hasSeatForNewMember()
 */
class SeatLimitPlanPolicy implements PlanPolicy, SeatPolicy
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function assertEligible(Subscribable $for, Plan $plan): void
    {
        if (! $for instanceof Tenant) {
            return;
        }

        $maxUsers = $plan->metadata()['options']['max_users'] ?? null;

        if (! is_numeric($maxUsers)) {
            return;
        }

        $maxUsers = (int) $maxUsers;
        $currentUsers = GetTenantSeatUsage::run($for)->members;

        if ($currentUsers > $maxUsers) {
            throw ValidationException::withMessages([
                'plan' => "You have {$currentUsers} users but this plan only allows {$maxUsers}. Please remove users or choose a higher tier plan.",
            ]);
        }
    }

    /**
     * Reads the entitlement rather than the plan directly, so a downgrade that
     * left the tenant over its new limit blocks the next addition instead of
     * being refused at swap time.
     */
    public function hasSeatForNewInvitation(Subscribable $for): bool
    {
        if (! $for instanceof Tenant) {
            return true;
        }

        $remaining = $this->entitlements->remaining(Entitlements::SEATS, $for);

        return $remaining === null || $remaining > 0;
    }

    public function hasSeatForNewMember(Subscribable $for): bool
    {
        if (! $for instanceof Tenant) {
            return true;
        }

        $limit = $this->entitlements->limit(Entitlements::SEATS, $for);

        // Members, not members plus pending invitations: an invitation that
        // was issued while a seat was free has to be acceptable.
        return $limit === null || GetTenantSeatUsage::run($for)->hasRoomForAnotherMember();
    }

    public function canSwap(Subscribable $for, Plan $from, Plan $to): bool
    {
        try {
            $this->assertEligible($for, $to);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }
}
