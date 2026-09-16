<?php

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Actions\Admin\StartImpersonation;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Actions\Tenancy\ReopenTenant;
use Nvade\Numerosis\Actions\Tenancy\RestoreTenant;
use Nvade\Numerosis\Actions\Tenancy\SuspendTenant;
use Nvade\Numerosis\Actions\Tenancy\TransferTenantOwnership;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Exceptions\Tenancy\OwnershipTransferBlocked;
use Nvade\Numerosis\Features\Admin\ImpersonationFeature;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\Policies\Tenancy\TenantPolicy;

new #[Layout('numerosis-layouts::staff')]
class extends Component
{
    use AuthorizesRequests;

    public Tenant $tenant;

    public function mount(string $tenantId): void
    {
        $this->tenant = Numerosis::model(Tenant::class)::findOrFail($tenantId);
    }

    /**
     * @return Collection<int, Membership>
     */
    #[Computed]
    public function memberships(): Collection
    {
        return Membership::query()
            ->with('user')
            ->where('tenant_id', $this->tenant->getKey())
            ->orderBy('role')
            ->get();
    }

    /**
     * @return Collection<int, Domain>
     */
    #[Computed]
    public function domains(): Collection
    {
        return $this->tenant->domains()->orderBy('domain')->get();
    }

    #[Computed]
    public function subscription(): ?Subscription
    {
        $subscription = $this->tenant->subscriptions()->latest()->first();

        return $subscription instanceof Subscription ? $subscription : null;
    }

    #[Computed]
    public function provision(): ?TenantProvision
    {
        return Numerosis::model(TenantProvision::class)::find($this->tenant->getKey());
    }

    /**
     * Null while the tenant database does not exist yet, which is every state
     * before `provisioned_at`. The switch runs through `runHere()`, so tenancy
     * ends again even if the count throws.
     */
    #[Computed]
    public function tenantUserCount(): ?int
    {
        if (! $this->tenant->isProvisioned()) {
            return null;
        }

        $count = $this->tenant->runHere(fn (): int => Numerosis::model(TenantUser::class)::query()->count());

        return is_int($count) ? $count : null;
    }

    public function suspend(): void
    {
        $this->authorize('update', $this->tenant);

        SuspendTenant::run($this->tenant);

        $this->record('suspended');
    }

    public function restore(): void
    {
        $this->authorize('restore', $this->tenant);

        RestoreTenant::run($this->tenant);

        $this->record('restored');
    }

    public function reopen(): void
    {
        $this->authorize('restore', $this->tenant);

        ReopenTenant::run($this->tenant);

        $this->record('reopened');
    }

    /**
     * Mints a one-time link and sends the staff user to the tenant host to
     * spend it. Gated on its own ability, so a staff user may administer
     * tenants without being able to sign in as their members.
     */
    public function impersonate(int $membershipId): mixed
    {
        $this->authorize(TenantPolicy::IMPERSONATE, $this->tenant);

        $membership = $this->membershipHere($membershipId);
        $staff = GetAuthenticatedUser::run(Context::Central->guard());

        if (! $membership instanceof Membership || ! $staff instanceof CentralUser) {
            return null;
        }

        try {
            $url = StartImpersonation::run($this->tenant, $membership->global_user_id, $staff);
        } catch (\DomainException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return null;
        }

        return $this->redirect($url);
    }

    /**
     * The membership id arrives as a plain Livewire argument, so it is scoped
     * to this tenant here rather than trusted from the rendered list.
     */
    public function reassignOwner(int $membershipId): void
    {
        $this->authorize('update', $this->tenant);

        $membership = $this->membershipHere($membershipId);

        if (! $membership instanceof Membership) {
            return;
        }

        try {
            TransferTenantOwnership::run($this->tenant, $membership);
        } catch (OwnershipTransferBlocked $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return;
        }

        $this->record('reassigned', ['user' => $membership->user?->email]);
    }

    private function membershipHere(int $membershipId): ?Membership
    {
        $membership = Membership::query()
            ->where('tenant_id', $this->tenant->getKey())
            ->whereKey($membershipId)
            ->first();

        return $membership instanceof Membership ? $membership : null;
    }

    /**
     * No `performedOn()`: `activity_log.subject_id` is an integer column and a
     * tenant key is a string.
     *
     * @param  array<string, string|null>  $replacements
     */
    private function record(string $action, array $replacements = []): void
    {
        $this->tenant->refresh();

        unset($this->memberships, $this->subscription, $this->provision, $this->tenantUserCount);

        $message = __("numerosis::staff.logged.{$action}", [
            'tenant' => (string) $this->tenant->getKey(),
            ...$replacements,
        ]);

        activity()
            ->causedBy(GetAuthenticatedUser::run())
            ->withProperties(['tenant_id' => $this->tenant->getKey()])
            ->log(is_string($message) ? $message : $action);

        $this->dispatch('notify', type: 'success', message: $message);
    }
}; ?>
<section class="mx-auto w-full max-w-5xl">
    <x-slot:title>{{ $tenant->name ?? $tenant->getKey() }}</x-slot:title>

    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <x-numerosis::ui.heading :level="1">{{ $tenant->name ?? $tenant->getKey() }}</x-numerosis::ui.heading>
                <x-numerosis::ui.subheading class="mt-1">{{ $tenant->getKey() }}</x-numerosis::ui.subheading>

                <div class="mt-2 flex items-center gap-2">
                    <x-numerosis::staff.tenant-status :tenant="$tenant"/>

                    @if ($tenant->isClosed())
                        <span class="text-xs text-zinc-500">
                            {{ __('numerosis::staff.tenant.purges_at', ['date' => $tenant->purgeAt()?->toFormattedDateString()]) }}
                        </span>
                    @endif
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if ($tenant->isClosed())
                    <flux:modal.trigger name="reopen-tenant">
                        <flux:button variant="primary" size="sm">{{ __('numerosis::staff.actions.reopen') }}</flux:button>
                    </flux:modal.trigger>
                @elseif ($tenant->isSuspended())
                    <flux:modal.trigger name="restore-tenant">
                        <flux:button variant="primary" size="sm">{{ __('numerosis::staff.actions.restore') }}</flux:button>
                    </flux:modal.trigger>
                @else
                    <flux:modal.trigger name="suspend-tenant">
                        <flux:button variant="danger" size="sm">{{ __('numerosis::staff.actions.suspend') }}</flux:button>
                    </flux:modal.trigger>
                @endif
            </div>
        </div>

        <x-numerosis::ui.grid :cols="3">
            <x-numerosis::card.stat :label="__('numerosis::staff.tenant.members')" :value="$this->memberships->count()"/>
            <x-numerosis::card.stat :label="__('numerosis::staff.tenant.domains')" :value="$this->domains->count()"/>
            <x-numerosis::card.stat
                :label="__('numerosis::staff.tenant.tenant_users')"
                :value="$this->tenantUserCount ?? '—'"
            />
        </x-numerosis::ui.grid>

        <x-numerosis::ui.card>
            <x-numerosis::ui.heading :level="2">{{ __('numerosis::staff.tenant.members') }}</x-numerosis::ui.heading>

            @if ($this->memberships->isEmpty())
                <p class="mt-2 text-sm text-zinc-500">{{ __('numerosis::staff.tenant.no_members') }}</p>
            @else
                <flux:table class="mt-4">
                    <flux:table.rows>
                        @foreach ($this->memberships as $membership)
                            <flux:table.row :key="$membership->getKey()">
                                <flux:table.cell>{{ $membership->user?->email ?? $membership->global_user_id }}</flux:table.cell>

                                <flux:table.cell>
                                    <flux:badge size="sm">{{ $membership->role->value }}</flux:badge>
                                </flux:table.cell>

                                <flux:table.cell class="text-right">
                                    @if (ImpersonationFeature::available() && auth()->user()?->can(TenantPolicy::IMPERSONATE, $tenant))
                                        <flux:button
                                            size="sm"
                                            variant="filled"
                                            wire:click="impersonate({{ $membership->getKey() }})"
                                        >
                                            {{ __('numerosis::staff.actions.impersonate') }}
                                        </flux:button>
                                    @endif

                                    @unless ($membership->isOwner())
                                        <flux:modal.trigger :name="'reassign-'.$membership->getKey()">
                                            <flux:button size="sm" variant="filled">
                                                {{ __('numerosis::staff.actions.reassign') }}
                                            </flux:button>
                                        </flux:modal.trigger>

                                        <flux:modal :name="'reassign-'.$membership->getKey()" class="max-w-md">
                                            <div class="space-y-4">
                                                <flux:heading size="lg">
                                                    {{ __('numerosis::staff.confirm.reassign', [
                                                        'user' => $membership->user?->email ?? $membership->global_user_id,
                                                        'tenant' => $tenant->getKey(),
                                                    ]) }}
                                                </flux:heading>

                                                <div class="flex justify-end gap-2">
                                                    <flux:modal.close>
                                                        <flux:button variant="filled">
                                                            {{ __('numerosis::staff.actions.never_mind') }}
                                                        </flux:button>
                                                    </flux:modal.close>

                                                    <flux:button
                                                        variant="primary"
                                                        wire:click="reassignOwner({{ $membership->getKey() }})"
                                                    >
                                                        {{ __('numerosis::staff.actions.confirm') }}
                                                    </flux:button>
                                                </div>
                                            </div>
                                        </flux:modal>
                                    @endunless
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-numerosis::ui.card>

        <x-numerosis::ui.card>
            <x-numerosis::ui.heading :level="2">{{ __('numerosis::staff.tenant.domains') }}</x-numerosis::ui.heading>

            @if ($this->domains->isEmpty())
                <p class="mt-2 text-sm text-zinc-500">{{ __('numerosis::staff.tenant.no_domains') }}</p>
            @else
                <ul class="mt-2 space-y-1 text-sm">
                    @foreach ($this->domains as $domain)
                        <li>{{ $domain->domain }}</li>
                    @endforeach
                </ul>
            @endif
        </x-numerosis::ui.card>

        <x-numerosis::ui.card>
            <x-numerosis::ui.heading :level="2">{{ __('numerosis::staff.tenant.subscription') }}</x-numerosis::ui.heading>

            @if ($this->subscription === null)
                <p class="mt-2 text-sm text-zinc-500">{{ __('numerosis::staff.tenant.no_subscription') }}</p>
            @else
                <dl class="mt-2 grid gap-2 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-zinc-500">{{ __('numerosis::staff.subscriptions.columns.plan') }}</dt>
                        <dd>{{ $this->subscription->paymentPlan?->name ?? $this->subscription->stripe_price ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('numerosis::staff.subscriptions.columns.status') }}</dt>
                        <dd>{{ $this->subscription->stripe_status }}</dd>
                    </div>
                </dl>
            @endif
        </x-numerosis::ui.card>

        <x-numerosis::ui.card>
            <x-numerosis::ui.heading :level="2">{{ __('numerosis::staff.tenant.provision') }}</x-numerosis::ui.heading>

            @if ($this->provision === null)
                <p class="mt-2 text-sm text-zinc-500">{{ __('numerosis::staff.tenant.no_provision') }}</p>
            @else
                <dl class="mt-2 grid gap-2 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-zinc-500">{{ __('numerosis::staff.provisions.columns.status') }}</dt>
                        <dd>{{ $this->provision->status->value }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('numerosis::staff.provisions.columns.steps') }}</dt>
                        <dd>{{ count($this->provision->step_records) }}</dd>
                    </div>
                    @if ($this->provision->error !== null)
                        <div class="sm:col-span-2">
                            <dt class="text-zinc-500">{{ __('numerosis::staff.provisions.columns.error') }}</dt>
                            <dd class="text-danger-text">{{ $this->provision->error }}</dd>
                        </div>
                    @endif
                </dl>
            @endif
        </x-numerosis::ui.card>
    </div>

    <flux:modal name="suspend-tenant" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">
                {{ __('numerosis::staff.confirm.suspend', ['tenant' => $tenant->getKey()]) }}
            </flux:heading>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('numerosis::staff.actions.never_mind') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="suspend">
                    {{ __('numerosis::staff.actions.suspend') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="restore-tenant" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">
                {{ __('numerosis::staff.confirm.restore', ['tenant' => $tenant->getKey()]) }}
            </flux:heading>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('numerosis::staff.actions.never_mind') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="primary" wire:click="restore">
                    {{ __('numerosis::staff.actions.restore') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="reopen-tenant" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">
                {{ __('numerosis::staff.confirm.reopen', ['tenant' => $tenant->getKey()]) }}
            </flux:heading>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('numerosis::staff.actions.never_mind') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="primary" wire:click="reopen">
                    {{ __('numerosis::staff.actions.reopen') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
