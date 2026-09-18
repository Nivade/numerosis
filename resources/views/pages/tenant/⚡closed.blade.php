<?php

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Routing\RouteNames;

/**
 * Where EnsureTenantSubscriptionActive sends anyone reaching a workspace its
 * owner closed. The owner gets the undo, everybody else gets the date the
 * data goes.
 */
new #[Layout('numerosis-layouts::auth')]
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

    #[Computed]
    public function canSeeClosureDetail(): bool
    {
        if ($this->isOwner) {
            return true;
        }

        $tenant = $this->tenant;
        $user = GetAuthenticatedUser::run('tenant');

        if ($tenant === null || $user === null) {
            return false;
        }

        return Membership::roleFor((string) $tenant->getTenantKey(), $user->global_id) === MembershipRole::Admin;
    }
};
?>
<div class="space-y-6 text-center">
    <div class="mx-auto flex size-14 items-center justify-center rounded-full bg-warning-bg">
        <flux:icon.archive-box class="size-7 text-warning-icon" />
    </div>

    <div class="space-y-2">
        <h1 class="text-xl font-bold text-zinc-900 dark:text-white">
            {{ __(':name is closed', ['name' => $this->tenant?->name ?? __('This workspace')]) }}
        </h1>
        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            @if ($this->tenant?->purgeAt() !== null)
                {{ __('Its data is kept until :date, then permanently deleted. Billing stops at the end of the period already paid for.', ['date' => $this->tenant->purgeAt()->toFormattedDayDateString()]) }}
            @else
                {{ __('Its data is kept for a recovery window, then permanently deleted.') }}
            @endif
        </p>
    </div>

    @if ($this->canSeeClosureDetail && $this->tenant?->closed_at !== null)
        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            {{ __('The owner closed this workspace on :date.', ['date' => $this->tenant->closed_at->toFormattedDayDateString()]) }}
        </p>
    @endif

    @if (session('status'))
        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ session('status') }}</p>
    @endif

    @if ($this->isOwner)
        {{-- `url()->current()`, not `route('tenant.reopen')`: path mode
             prefixes the tenant group `{tenant}` and nothing registers a URL
             default for it. --}}
        <form method="POST" action="{{ url()->current().'/reopen' }}">
            @csrf
            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('Reopen this workspace') }}
            </flux:button>
        </form>
    @else
        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            {{ __('Ask the workspace owner to reopen it before that date.') }}
        </p>
    @endif

    <flux:button href="{{ route(RouteNames::home()) }}" variant="ghost" size="sm">
        {{ __('Back to home') }}
    </flux:button>
</div>
