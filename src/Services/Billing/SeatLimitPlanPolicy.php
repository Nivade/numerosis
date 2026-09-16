<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing;

use Illuminate\Validation\ValidationException;
use Nvade\Numerosis\Actions\Queries\GetTenantSeatUsage;
use Nvade\Numerosis\Contracts\Billing\Plan;
use Nvade\Numerosis\Contracts\Billing\PlanPolicy;
use Nvade\Numerosis\Contracts\Subscribable;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Refuses a plan whose seat limit the tenant already exceeds, checked when
 * starting a checkout, when swapping plans, and on the membership path
 * through {@see hasSeatForNewMember()}. `options.max_users` comes from
 * host-editable config, so a non-numeric value is treated as no limit, exactly
 * as an absent one is: a malformed entry must not lock a customer out of a
 * plan they are paying for.
 */
class SeatLimitPlanPolicy implements PlanPolicy
{
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
        $currentUsers = $for->users()->count();

        if ($currentUsers > $maxUsers) {
            throw ValidationException::withMessages([
                'plan' => "You have {$currentUsers} users but this plan only allows {$maxUsers}. Please remove users or choose a higher tier plan.",
            ]);
        }
    }

    public function hasSeatForNewInvitation(Subscribable $for): bool
    {
        return ! $for instanceof Tenant
            || GetTenantSeatUsage::run($for)->hasRoomForAnotherInvitation();
    }

    public function hasSeatForNewMember(Subscribable $for): bool
    {
        return ! $for instanceof Tenant
            || GetTenantSeatUsage::run($for)->hasRoomForAnotherMember();
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
