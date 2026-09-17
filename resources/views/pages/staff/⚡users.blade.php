<?php

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Fortify\Features;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Nvade\Numerosis\Actions\Auth\AnonymizeUserForStaff;
use Nvade\Numerosis\Actions\Auth\ClearTwoFactorAuthentication;
use Nvade\Numerosis\Actions\Auth\ExportUserDataForStaff;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Numerosis;

new #[Layout('numerosis-layouts::staff')]
class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * The support path for a lost device. Gated on its own ability, so a staff
     * user may read the user list without being able to strip anyone's second
     * factor.
     */
    public function clearTwoFactor(int $userId): void
    {
        $staff = GetAuthenticatedUser::run(Context::Central->guard());

        abort_unless(
            $staff instanceof CentralUser && $staff->hasPermissionTo(ClearTwoFactorAuthentication::ability()),
            403
        );

        $target = Numerosis::model(CentralUser::class)::query()->find($userId);

        if (! $target instanceof CentralUser) {
            return;
        }

        ClearTwoFactorAuthentication::run($staff, $target);

        unset($this->users);

        $this->dispatch('notify', type: 'success', message: __('numerosis::staff.users.two_factor_cleared'));
    }

    /** Starts an export for somebody who asked by mail; the link still goes to them. */
    public function exportData(int $userId): void
    {
        $staff = $this->staffWith(ExportUserDataForStaff::ability());
        $target = Numerosis::model(CentralUser::class)::query()->find($userId);

        if (! $target instanceof CentralUser) {
            return;
        }

        try {
            ExportUserDataForStaff::run($staff, $target);
        } catch (ShowsMessageToUser $refusal) {
            $this->dispatch('notify', type: 'error', message: $refusal->getMessage());

            return;
        }

        $this->dispatch('notify', type: 'success', message: __('numerosis::staff.users.export_started'));
    }

    public function eraseData(int $userId): void
    {
        $staff = $this->staffWith(AnonymizeUserForStaff::ability());
        $target = Numerosis::model(CentralUser::class)::query()->find($userId);

        if (! $target instanceof CentralUser) {
            return;
        }

        $erased = AnonymizeUserForStaff::run($staff, $target);

        unset($this->users);

        $this->dispatch(
            'notify',
            type: $erased ? 'success' : 'error',
            message: $erased
                ? __('numerosis::staff.users.erased')
                : __('numerosis::staff.users.erase_blocked'),
        );
    }

    private function staffWith(string $ability): CentralUser
    {
        $staff = GetAuthenticatedUser::run(Context::Central->guard());

        abort_unless($staff instanceof CentralUser && $staff->hasPermissionTo($ability), 403);

        return $staff;
    }

    #[Computed]
    public function canExportData(): bool
    {
        $staff = GetAuthenticatedUser::run(Context::Central->guard());

        return $staff instanceof CentralUser && $staff->hasPermissionTo(ExportUserDataForStaff::ability());
    }

    #[Computed]
    public function canEraseData(): bool
    {
        $staff = GetAuthenticatedUser::run(Context::Central->guard());

        return $staff instanceof CentralUser && $staff->hasPermissionTo(AnonymizeUserForStaff::ability());
    }

    #[Computed]
    public function canClearTwoFactor(): bool
    {
        $staff = GetAuthenticatedUser::run(Context::Central->guard());

        return Features::canManageTwoFactorAuthentication()
            && $staff instanceof CentralUser
            && $staff->hasPermissionTo(ClearTwoFactorAuthentication::ability());
    }

    /**
     * @return LengthAwarePaginator<int, CentralUser>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        return Numerosis::model(CentralUser::class)::query()
            ->with('tenants')
            ->when($this->search !== '', fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner
                    ->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('email', 'like', '%'.$this->search.'%')
            ))
            ->orderByDesc('created_at')
            ->paginate(25);
    }
}; ?>
<section class="mx-auto w-full max-w-5xl">
    <x-slot:title>{{ __('numerosis::staff.users.heading') }}</x-slot:title>

    <div class="flex flex-col gap-6">
        <div>
            <x-numerosis::ui.heading :level="1">{{ __('numerosis::staff.users.heading') }}</x-numerosis::ui.heading>
            <x-numerosis::ui.subheading class="mt-1">{{ __('numerosis::staff.users.subheading') }}</x-numerosis::ui.subheading>
        </div>

        <flux:input
            wire:model.live.debounce.300ms="search"
            class="max-w-xs"
            icon="magnifying-glass"
            :placeholder="__('numerosis::staff.users.search')"
        />

        <x-numerosis::ui.card>
            @if ($this->users->isEmpty())
                <x-numerosis::ui.empty-state :title="__('numerosis::staff.users.empty')"/>
            @else
                <flux:table :paginate="$this->users">
                    <flux:table.columns>
                        <flux:table.column>{{ __('numerosis::staff.users.columns.user') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.users.columns.email') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.users.columns.tenants') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.users.columns.two_factor') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.users.columns.joined') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.users.columns.privacy') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->users as $user)
                            <flux:table.row :key="$user->getKey()">
                                <flux:table.cell>{{ $user->name }}</flux:table.cell>
                                <flux:table.cell>{{ $user->email }}</flux:table.cell>
                                <flux:table.cell>
                                    {{ $user->tenants->map(fn ($tenant) => $tenant->getKey())->implode(', ') ?: '—' }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if ($user->hasEnabledTwoFactorAuthentication())
                                        <flux:badge size="sm" color="lime">{{ __('numerosis::staff.users.two_factor.on') }}</flux:badge>

                                        @if ($this->canClearTwoFactor)
                                            <flux:button
                                                wire:click="clearTwoFactor({{ $user->getKey() }})"
                                                wire:confirm="{{ __('numerosis::staff.actions.clear_two_factor') }}?"
                                                variant="ghost"
                                                size="sm"
                                            >
                                                {{ __('numerosis::staff.actions.clear_two_factor') }}
                                            </flux:button>
                                        @endif
                                    @else
                                        <flux:badge size="sm">{{ __('numerosis::staff.users.two_factor.off') }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>{{ $user->created_at?->toFormattedDateString() ?? '—' }}</flux:table.cell>
                                <flux:table.cell>
                                    @if ($this->canExportData)
                                        <flux:button wire:click="exportData({{ $user->getKey() }})" variant="ghost" size="sm">
                                            {{ __('numerosis::staff.users.export') }}
                                        </flux:button>
                                    @endif

                                    @if ($this->canEraseData && $user->anonymized_at === null)
                                        <flux:button
                                            wire:click="eraseData({{ $user->getKey() }})"
                                            wire:confirm="{{ __('numerosis::staff.users.erase_confirm') }}"
                                            variant="ghost"
                                            size="sm"
                                        >
                                            {{ __('numerosis::staff.users.erase') }}
                                        </flux:button>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-numerosis::ui.card>
    </div>
</section>
