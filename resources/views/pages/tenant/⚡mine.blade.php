<?php

use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Actions\Tenancy\MarkProvisionCancelled;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Numerosis;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('layouts::app')]
class extends Component
{
    public ?CentralUser $user = null;

    /** Tenants that finished provisioning and can be visited. */
    public Collection $readyTenants;

    /** Tenants whose row exists but whose database is still being built. */
    public Collection $provisioningTenants;

    /** Domains claimed at checkout that have no tenant row yet. */
    public Collection $pendingTenants;

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
    #[On('echo-private:user.{user.id},.tenant.provisioned')]
    #[On('echo-private:user.{user.id},.tenant.provisioning-failed')]
    #[On('echo-private:user.{user.id},.tenant.provisioning-cancelled')]
    public function refreshTenants(): void
    {
        if (! $this->user) {
            $this->readyTenants = new Collection;
            $this->provisioningTenants = new Collection;
            $this->pendingTenants = new Collection;

            return;
        }

        // Eager loaded because the ready-tenant list reads each tenant's
        // latest subscription to decide whether to show the awaiting-payment
        // notice, and Cashier's latestSubscription() issues a fresh query
        // every call. The relation is already ordered created_at desc
        // (ManagesSubscriptions::subscriptions()), so the first loaded row is
        // that same latest subscription.
        $tenants = $this->user->tenants()->with('subscriptions')->get();

        $this->readyTenants = $tenants->whereNotNull('provisioned_at');
        $this->provisioningTenants = $tenants->whereNull('provisioned_at');

        $this->pendingTenants = Numerosis::model(PendingTenantProvision::class)::query()
            ->where('global_id', $this->user->global_id)
            ->whereNotIn('domain', $tenants->pluck('id'))
            ->get();
    }

    /**
     * Ownership is re-checked here rather than trusted from the rendered
     * list — $domain arrives as a plain Livewire method argument, which is
     * client-controlled the same way a route parameter is.
     */
    public function cancelProvision(string $domain): void
    {
        $owned = Numerosis::model(PendingTenantProvision::class)::where('domain', $domain)
            ->where('global_id', $this->user?->global_id)
            ->exists();

        if (! $owned) {
            return;
        }

        MarkProvisionCancelled::run($domain);

        $this->refreshTenants();
    }

    /**
     * The broadcast is the fast path, but a dropped websocket must not leave
     * the user staring at a spinner forever, so poll while work is outstanding.
     */
    public function isWorkOutstanding(): bool
    {
        return $this->provisioningTenants->isNotEmpty()
            || $this->pendingTenants->contains(fn (PendingTenantProvision $p) => ! $p->hasFailed());
    }
}; ?>
<section class=" docsearch-content overflow-hidden mx-auto max-w-prose w-full h-full content-center">
    <x-slot:title>Your Tenants</x-slot:title>
    <div class="flex w-full flex-1 flex-col gap-4 ">
        <div class="flex items-start justify-between">
            <div>
                <x-numerosis::ui.heading :level="1">Your Tenants</x-numerosis::ui.heading>
                <x-numerosis::ui.subheading class="mt-1">All organizations you belong to</x-numerosis::ui.subheading>
            </div>

            <div class="flex items-center gap-3">
                <x-numerosis::ui.badge variant="default">
                    {{ $readyTenants->count() }} total
                </x-numerosis::ui.badge>
                @if (\Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Tenancy\RegistrationWizardFeature::NAME))
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
                    @if (\Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Tenancy\RegistrationWizardFeature::NAME))
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
                            <x-numerosis::ui.list.item class="flex flex-row gap-3 justify-between">
                                <div class="min-w-0 flex items-start gap-3">
                                    <x-numerosis::ui.avatar class="hidden sm:flex" initials="…" />

                                    <div class="min-w-0">
                                        <x-numerosis::ui.text variant="default" size="sm" class="font-medium truncate">
                                            {{ $pending->company_name }}
                                        </x-numerosis::ui.text>
                                        <x-numerosis::ui.text variant="subtle" size="xs" class="mt-0.5 truncate">
                                            @if($pending->hasFailed())
                                                Setup failed: {{ $pending->error }}
                                            @else
                                                {{ $pending->domain }} — setting up…
                                            @endif
                                        </x-numerosis::ui.text>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 shrink-0 justify-end">
                                    @if($pending->hasFailed())
                                        @if (\Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Tenancy\RegistrationWizardFeature::NAME))
                                            <flux:button href="{{ route('tenants.create') }}" wire:navigate variant="ghost" size="sm">
                                                Try again
                                            </flux:button>
                                        @endif
                                    @elseif($pending->status === TenantProvisionStatus::Reserved)
                                        <flux:button href="{{ route('checkout.resume', $pending->domain) }}" wire:navigate variant="primary" size="sm">
                                            Continue checkout
                                        </flux:button>

                                        <flux:modal.trigger name="cancel-provision-{{ $pending->domain }}">
                                            <flux:button variant="danger" size="sm">
                                                Cancel
                                            </flux:button>
                                        </flux:modal.trigger>

                                        <flux:modal name="cancel-provision-{{ $pending->domain }}" class="max-w-sm">
                                            <div class="space-y-6">
                                                <div>
                                                    <flux:heading size="lg">Cancel this reservation?</flux:heading>
                                                    <flux:subheading>
                                                        {{ $pending->domain }} will be released and free for anyone to claim. {{ $pending->company_name }} will no longer be able to continue this checkout.
                                                    </flux:subheading>
                                                </div>

                                                <div class="flex justify-end gap-2">
                                                    <flux:modal.close>
                                                        <flux:button variant="filled">Keep reservation</flux:button>
                                                    </flux:modal.close>

                                                    <flux:button
                                                        variant="danger"
                                                        wire:click="cancelProvision('{{ $pending->domain }}')"
                                                    >
                                                        Cancel reservation
                                                    </flux:button>
                                                </div>
                                            </div>
                                        </flux:modal>
                                    @else
                                        <flux:icon.loading class="h-4 w-4 text-zinc-400" />
                                    @endif
                                </div>
                            </x-numerosis::ui.list.item>
                        @endforeach

                        @foreach($provisioningTenants as $tenant)
                            <x-numerosis::ui.list.item class="flex flex-row gap-3 justify-between">
                                <div class="min-w-0 flex items-start gap-3">
                                    <x-numerosis::ui.avatar class="hidden sm:flex" :initials="$tenant->initials ?: 'T'" />

                                    <div class="min-w-0">
                                        <x-numerosis::ui.text variant="default" size="sm" class="font-medium truncate">
                                            {{ $tenant->name }}
                                        </x-numerosis::ui.text>
                                        <x-numerosis::ui.text variant="subtle" size="xs" class="mt-0.5 truncate">
                                            Setting up…
                                        </x-numerosis::ui.text>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 shrink-0 justify-end">
                                    <flux:icon.loading class="h-4 w-4 text-zinc-400" />
                                </div>
                            </x-numerosis::ui.list.item>
                        @endforeach
                    </div>

                    @php /** @var Tenant $tenant */ @endphp
                    @foreach($readyTenants as $tenant)
                        @php
                            // Unreachable for cards, which settle synchronously — see
                            // custom-checkout.md, "Provisioning and settlement". The
                            // pending row that tracked AwaitingPayment during
                            // provisioning is long gone by the time a tenant is
                            // "ready", so the subscription itself is the only signal
                            // that survives.
                            $subscription = $tenant->subscriptions->first();
                            $awaitingPayment = $subscription && ! in_array($subscription->stripe_status, ['active', 'trialing'], true);
                        @endphp
                        <x-numerosis::ui.list.item class="flex flex-row gap-3 justify-between focus-within:ring-2 focus-within:ring-black/10 dark:focus-within:ring-white/15">
                            <div class="min-w-0 flex items-start gap-3">
                                <x-numerosis::ui.avatar class="hidden sm:flex" :initials="$tenant->initials ?: 'T'" />

                                <div class="min-w-0">
                                    <x-numerosis::ui.text variant="default" size="sm" class="font-medium truncate">
                                        {{ $tenant->name }}
                                    </x-numerosis::ui.text>
                                    <x-numerosis::ui.text variant="subtle" size="xs" class="mt-0.5 truncate">
                                        {{ $tenant->primaryDomain()->domain ?? 'No domain configured' }}
                                    </x-numerosis::ui.text>
                                    @if($awaitingPayment)
                                        <x-numerosis::billing.awaiting-payment-card class="mt-2" />
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-2 shrink-0 justify-end">
                                @if($tenant->primaryDomain())
                                <flux:button
                                    tag="a"
                                    href="{{ $tenant->primaryDomain()->url }}"
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
                            </div>
                        </x-numerosis::ui.list.item>
                    @endforeach
                </x-numerosis::ui.list>
            @endif
        </x-numerosis::ui.card>
    </div>
</section>
