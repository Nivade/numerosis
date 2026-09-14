<?php

use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Actions\Queries\GetTenantProvisionsByGlobalId;
use Nvade\Numerosis\Actions\Tenancy\MarkProvisionCancelled;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Features\Tenancy\RegistrationWizardFeature;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

new #[Layout('numerosis-layouts::app')]
class extends Component
{
    public ?CentralUser $user = null;

    /** Tenants that finished provisioning and can be visited. */
    public Collection $readyTenants;

    /** Tenants whose row exists but whose database is still being built. */
    public Collection $provisioningTenants;

    /** Slugs claimed at checkout that have no tenant row yet. */
    public Collection $pendingTenants;

    /**
     * Every provision row this user owns, keyed by slug, so a tenant still
     * being built can show which step is running. A provisioning tenant's row
     * is deliberately absent from $pendingTenants, which excludes any slug
     * that already has a tenant.
     */
    public Collection $provisionsBySlug;

    public function mount(): void
    {
        // Narrowed rather than assigned straight through: this page is central
        // and its property is a CentralUser, but the guard can also hold a
        // Tenant\User, which would be a TypeError on assignment.
        $user = GetAuthenticatedUser::run();

        $this->user = $user instanceof CentralUser ? $user : null;

        $this->refreshTenants();
    }

    /**
     * Readiness is `provisioned_at`, never "a tenant row exists" — the Stripe
     * webhook creates tenant rows on a path that has no pending row, so an
     * unprovisioned tenant would otherwise render as ready and link to a
     * database that does not exist yet.
     */
    public function refreshTenants(): void
    {
        if (! $this->user) {
            $this->readyTenants = new Collection;
            $this->provisioningTenants = new Collection;
            $this->pendingTenants = new Collection;
            $this->provisionsBySlug = new Collection;

            return;
        }

        // Eager loaded because the ready-tenant list reads each tenant's
        // latest subscription to decide whether to show the awaiting-payment
        // notice, and Cashier's latestSubscription() issues a fresh query
        // every call. The relation is already ordered created_at desc
        // (ManagesSubscriptions::subscriptions()), so the first loaded row is
        // that same latest subscription.
        // Deliberately the relation and not GetTenantsByGlobalId: that action
        // caches ids and hydrates without the eager load this page needs.
        $tenants = $this->user->tenants()->with('subscriptions')->get();

        $this->readyTenants = $tenants->whereNotNull('provisioned_at');
        $this->provisioningTenants = $tenants->whereNull('provisioned_at');

        $this->provisionsBySlug = GetTenantProvisionsByGlobalId::run($this->user->global_id);

        $this->pendingTenants = $this->provisionsBySlug
            ->reject(fn (TenantProvision $p) => $tenants->contains('id', $p->slug))
            ->values();
    }

    /**
     * Ownership is re-checked here rather than trusted from the rendered
     * list — $domain arrives as a plain Livewire method argument, which is
     * client-controlled the same way a route parameter is.
     */
    public function cancelProvision(string $domain): void
    {
        $owned = Numerosis::model(TenantProvision::class)::ownedBy($domain, $this->user?->global_id);

        if (! $owned) {
            return;
        }

        MarkProvisionCancelled::run($domain);

        $this->refreshTenants();
    }

    /**
     * Polled while true so a still-provisioning tenant surfaces without a
     * manual refresh.
     */
    public function isWorkOutstanding(): bool
    {
        return $this->provisioningTenants->isNotEmpty()
            || $this->pendingTenants->contains(fn (TenantProvision $p) => ! $p->hasFailed());
    }
}; ?>
<section class=" docsearch-content overflow-hidden mx-auto max-w-prose w-full h-full content-center">
    <x-slot:title>Your Tenants</x-slot:title>
    <div class="flex w-full flex-1 flex-col gap-4 ">
        {{-- Where the invitation controllers land an authenticated visitor
             carrying a domain-exception message, since Fortify's `login`
             would bounce them off `guest` and drop the flash. --}}
        <x-numerosis::ui.auth-session-status :status="session('status')" />

        <div class="flex items-start justify-between">
            <div>
                <x-numerosis::ui.heading :level="1">Your Tenants</x-numerosis::ui.heading>
                <x-numerosis::ui.subheading class="mt-1">All organizations you belong to</x-numerosis::ui.subheading>
            </div>

            <div class="flex items-center gap-3">
                <x-numerosis::ui.badge variant="default">
                    {{ $readyTenants->count() }} total
                </x-numerosis::ui.badge>
                @if (RegistrationWizardFeature::available())
                    <flux:button href="{{ route('tenants.create') }}" wire:navigate icon="plus" variant="primary" size="sm">
                        New Tenant
                    </flux:button>
                @endif
            </div>
        </div>

        <x-numerosis::ui.card :padding="false">
            @if($readyTenants->isEmpty() && $provisioningTenants->isEmpty() && $pendingTenants->isEmpty())
                <x-numerosis::ui.empty-state
                    icon="folder-plus"
                    title="You're not a member of any tenants yet"
                    description="Create your first tenant to get started, or ask an owner to invite you."
                >
                    @if (RegistrationWizardFeature::available())
                        <x-slot:action>
                            <flux:button href="{{ route('tenants.create') }}" wire:navigate variant="primary">
                                Create Tenant
                            </flux:button>
                        </x-slot:action>
                    @endif
                </x-numerosis::ui.empty-state>
            @else
                <x-numerosis::ui.list>
                    <div @if($this->isWorkOutstanding()) wire:poll.5s="refreshTenants" @endif>
                        @foreach($pendingTenants as $pending)
                            <x-numerosis::tenant.list-item initials="…" :title="$pending->name">
                                <x-slot:subtitle>
                                    @if($pending->hasFailed())
                                        Setup failed: {{ $pending->error }}
                                    @elseif($pending->status === TenantProvisionStatus::Reserved)
                                        {{ $pending->slug }} — awaiting checkout
                                    @else
                                        {{ $pending->slug }} — {{ $pending->currentStepLabel() }}
                                    @endif
                                </x-slot:subtitle>

                                <x-slot:actions>
                                    @if($pending->hasFailed())
                                        @if (RegistrationWizardFeature::available())
                                            <flux:button href="{{ route('tenants.create') }}" wire:navigate variant="ghost" size="sm">
                                                Try again
                                            </flux:button>
                                        @endif
                                    @elseif($pending->status === TenantProvisionStatus::Reserved)
                                        <flux:button href="{{ route('checkout.resume', $pending->slug) }}" wire:navigate variant="primary" size="sm">
                                            Continue checkout
                                        </flux:button>

                                        <flux:modal.trigger name="cancel-provision-{{ $pending->slug }}">
                                            <flux:button variant="danger" size="sm">
                                                Cancel
                                            </flux:button>
                                        </flux:modal.trigger>

                                        <flux:modal name="cancel-provision-{{ $pending->slug }}" class="max-w-sm">
                                            <div class="space-y-6">
                                                <div>
                                                    <flux:heading size="lg">Cancel this reservation?</flux:heading>
                                                    <flux:subheading>
                                                        {{ $pending->slug }} will be released and free for anyone to claim. {{ $pending->name }} will no longer be able to continue this checkout.
                                                    </flux:subheading>
                                                </div>

                                                <div class="flex justify-end gap-2">
                                                    <flux:modal.close>
                                                        <flux:button variant="filled">Keep reservation</flux:button>
                                                    </flux:modal.close>

                                                    <flux:button
                                                        variant="danger"
                                                        wire:click="cancelProvision('{{ $pending->slug }}')"
                                                    >
                                                        Cancel reservation
                                                    </flux:button>
                                                </div>
                                            </div>
                                        </flux:modal>
                                    @else
                                        <flux:icon.loading class="h-4 w-4 text-zinc-400" />
                                    @endif
                                </x-slot:actions>
                            </x-numerosis::tenant.list-item>
                        @endforeach

                        @foreach($provisioningTenants as $tenant)
                            <x-numerosis::tenant.list-item :initials="$tenant->initials ?: 'T'" :title="$tenant->name">
                                <x-slot:subtitle>
                                    {{ $provisionsBySlug->get($tenant->id)?->currentStepLabel()
                                        ?? __('numerosis::tenancy.provisioning.fallback') }}
                                </x-slot:subtitle>

                                <x-slot:actions>
                                    <flux:icon.loading class="h-4 w-4 text-zinc-400" />
                                </x-slot:actions>
                            </x-numerosis::tenant.list-item>
                        @endforeach
                    </div>

                    @php /** @var Tenant $tenant */ @endphp
                    @foreach($readyTenants as $tenant)
                        @php
                            // Unreachable for cards, which settle synchronously. Read
                            // off the subscription rather than the provision row's
                            // settled_at: by the time a tenant is ready, the
                            // subscription is the authoritative signal.
                            $subscription = $tenant->subscriptions->first();
                            $awaitingPayment = $subscription && ! $subscription->isSettled();

                            // Read once for the three uses below: primaryDomain()
                            // is a cache round trip, and on a cold cache a query.
                            $primaryDomain = $tenant->primaryDomain();
                        @endphp
                        <x-numerosis::tenant.list-item
                            :initials="$tenant->initials ?: 'T'"
                            :title="$tenant->name"
                            class="focus-within:ring-2 focus-within:ring-black/10 dark:focus-within:ring-white/15"
                        >
                            <x-slot:subtitle>{{ $primaryDomain->domain ?? 'No domain configured' }}</x-slot:subtitle>

                            @if($awaitingPayment)
                                <x-numerosis::billing.awaiting-payment-card class="mt-2" />
                            @endif

                            <x-slot:actions>
                                @if($primaryDomain)
                                    <flux:button
                                        tag="a"
                                        href="{{ $primaryDomain->url }}"
                                        target="_blank"
                                        rel="noopener"
                                        variant="primary"
                                        size="sm"
                                        aria-label="Visit {{ $tenant->name }} (opens in a new tab)"
                                    >
                                        <span class="inline-flex items-center gap-1">
                                            <span>Visit</span>
                                            <flux:icon.arrow-top-right-on-square
                                                class="h-4 w-4 motion-safe:transition-transform motion-safe:duration-200 motion-safe:group-hover:translate-x-0.5 motion-safe:group-hover:-translate-y-0.5"/>
                                        </span>
                                    </flux:button>
                                @endif
                            </x-slot:actions>
                        </x-numerosis::tenant.list-item>
                    @endforeach
                </x-numerosis::ui.list>
            @endif
        </x-numerosis::ui.card>
    </div>
</section>
