<?php

use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Models\Central\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Where EnsureTenantSubscriptionActive sends a suspended tenant's owner —
 * what happened, what it costs, one button to fix it. A middleware that
 * only ever 403s produces a support ticket; one that routes here with a
 * billing-portal link produces a payment.
 */
new #[Layout('layouts::auth')]
class extends Component
{
    #[Computed]
    public function tenant(): ?Tenant
    {
        $current = tenant();

        return $current instanceof Tenant ? $current : null;
    }

    #[Computed]
    public function isOwner(): bool
    {
        $user = GetAuthenticatedUser::run('tenant');
        $owner = $this->tenant?->owner();

        return $user !== null && $owner !== null && $owner->global_id === $user->global_id;
    }
};
?>
<div class="space-y-6 text-center">
    <div class="mx-auto flex size-14 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-950/50">
        <flux:icon.exclamation-triangle class="size-7 text-amber-600 dark:text-amber-400" />
    </div>

    <div class="space-y-2">
        <h1 class="text-xl font-bold text-zinc-900 dark:text-white">
            Access to {{ $this->tenant?->name ?? 'this workspace' }} is paused
        </h1>
        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            We couldn't collect payment, so access has been paused. Your data has not been deleted —
            fixing your payment method restores access immediately.
        </p>
    </div>

    @if($this->isOwner)
        <flux:button
            tag="a"
            href="{{ route('billing-portal') }}"
            variant="primary"
            class="w-full bg-linear-to-r from-blue-600 to-purple-600 border-none"
        >
            Update payment method
        </flux:button>
    @else
        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            Ask the workspace owner to update the payment method to restore access.
        </p>
    @endif

    <flux:button href="{{ route(\Nvade\Numerosis\Support\Routes\RouteNames::home()) }}" variant="ghost" size="sm">
        Back to home
    </flux:button>
</div>
