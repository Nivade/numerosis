<?php

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;

new #[Layout('numerosis-layouts::staff')]
class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'all';

    public function updated(): void
    {
        $this->resetPage();
    }

    /**
     * Owner and plan are eager loaded rather than read through
     * `Tenant::owner()`, which is one cache round trip per row.
     *
     * @return LengthAwarePaginator<int, Tenant>
     */
    #[Computed]
    public function tenants(): LengthAwarePaginator
    {
        return Numerosis::model(Tenant::class)::query()
            // Owners only: the listing shows one email per tenant, and loading
            // every member to find it hydrates thousands of rows a page.
            ->with([
                'users' => fn (BelongsToMany $members) => $members->wherePivot('role', MembershipRole::Owner->value),
                'subscriptions.paymentPlan',
            ])
            ->when($this->search !== '', fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner
                    ->where('id', 'like', '%'.$this->search.'%')
                    // `name` is folded into the `data` JSON column, so it has
                    // no column of its own to match against.
                    ->orWhere('data->name', 'like', '%'.$this->search.'%')
                    ->orWhereHas('domains', fn (Builder $domains) => $domains->where('domain', 'like', '%'.$this->search.'%'))
            ))
            ->when($this->status === 'active', fn (Builder $query) => $query->whereNull('suspended_at')->whereNull('closed_at'))
            ->when($this->status === 'suspended', fn (Builder $query) => $query->whereNotNull('suspended_at'))
            ->when($this->status === 'closed', fn (Builder $query) => $query->whereNotNull('closed_at'))
            ->when($this->status === 'failed', fn (Builder $query) => $query->whereIn(
                'id',
                Numerosis::model(TenantProvision::class)::query()
                    ->where('status', TenantProvisionStatus::Failed)
                    ->select('slug')
            ))
            ->orderByDesc('created_at')
            ->paginate(25);
    }

    /**
     * `Tenant` casts `suspended_at` and `closed_at` and not this one, so it
     * arrives as the raw column value.
     */
    public function provisionedLabel(Tenant $tenant): string
    {
        $provisionedAt = $tenant->provisioned_at;

        return $provisionedAt === null ? '—' : Carbon::parse($provisionedAt)->toFormattedDateString();
    }
}; ?>
<section class="mx-auto w-full max-w-5xl">
    <x-slot:title>{{ __('numerosis::staff.tenants.heading') }}</x-slot:title>

    <div class="flex flex-col gap-6">
        <div>
            <x-numerosis::ui.heading :level="1">{{ __('numerosis::staff.tenants.heading') }}</x-numerosis::ui.heading>
            <x-numerosis::ui.subheading class="mt-1">{{ __('numerosis::staff.tenants.subheading') }}</x-numerosis::ui.subheading>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <flux:input
                wire:model.live.debounce.300ms="search"
                class="max-w-xs"
                icon="magnifying-glass"
                :placeholder="__('numerosis::staff.tenants.search')"
            />

            <flux:select wire:model.live="status" class="max-w-40">
                <flux:select.option value="all">{{ __('numerosis::staff.filters.all') }}</flux:select.option>
                <flux:select.option value="active">{{ __('numerosis::staff.filters.active') }}</flux:select.option>
                <flux:select.option value="suspended">{{ __('numerosis::staff.filters.suspended') }}</flux:select.option>
                <flux:select.option value="closed">{{ __('numerosis::staff.filters.closed') }}</flux:select.option>
                <flux:select.option value="failed">{{ __('numerosis::staff.filters.failed') }}</flux:select.option>
            </flux:select>
        </div>

        <x-numerosis::ui.card>
            @if ($this->tenants->isEmpty())
                <x-numerosis::ui.empty-state :title="__('numerosis::staff.tenants.empty')"/>
            @else
                <flux:table :paginate="$this->tenants">
                    <flux:table.columns>
                        <flux:table.column>{{ __('numerosis::staff.tenants.columns.tenant') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.tenants.columns.owner') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.tenants.columns.plan') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.tenants.columns.status') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.tenants.columns.provisioned') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->tenants as $tenant)
                            @php
                                $owner = $tenant->users->first(fn ($member) => $member->pivot?->isOwner());
                                $subscription = $tenant->subscriptions->first();
                            @endphp
                            <flux:table.row :key="$tenant->getKey()">
                                <flux:table.cell>
                                    <flux:link :href="route('staff.tenants.show', $tenant->getKey())" wire:navigate>
                                        {{ $tenant->name ?? $tenant->getKey() }}
                                    </flux:link>
                                    <div class="text-xs text-zinc-500">{{ $tenant->getKey() }}</div>
                                </flux:table.cell>

                                <flux:table.cell>{{ $owner?->email ?? '—' }}</flux:table.cell>

                                <flux:table.cell>{{ $subscription?->paymentPlan?->name ?? '—' }}</flux:table.cell>

                                <flux:table.cell>
                                    <x-numerosis::staff.tenant-status :tenant="$tenant"/>
                                </flux:table.cell>

                                <flux:table.cell>{{ $this->provisionedLabel($tenant) }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-numerosis::ui.card>
    </div>
</section>
