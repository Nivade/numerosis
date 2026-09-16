<?php

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Actions\Tenancy\MarkProvisionCancelled;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;

new #[Layout('numerosis-layouts::staff')]
class extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    #[Url]
    public string $status = 'all';

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, TenantProvision>
     */
    #[Computed]
    public function provisions(): LengthAwarePaginator
    {
        return Numerosis::model(TenantProvision::class)::query()
            ->when(
                TenantProvisionStatus::tryFrom($this->status) instanceof TenantProvisionStatus,
                fn (Builder $query) => $query->where('status', $this->status)
            )
            ->orderByDesc('created_at')
            ->paginate(25);
    }

    /**
     * Re-queues the same chain. Each step reads its own record on the row, so
     * the ones that already ran skip themselves.
     */
    public function retry(string $slug): void
    {
        $provision = $this->authorizedProvision($slug);

        if (! $provision instanceof TenantProvision) {
            return;
        }

        app(ProvisionsTenant::class)->queue(TenantProvisionData::fromProvision($provision));

        $this->record('provision_retried', $slug);
    }

    public function cancel(string $slug): void
    {
        $provision = $this->authorizedProvision($slug);

        if (! $provision instanceof TenantProvision) {
            return;
        }

        MarkProvisionCancelled::run($slug);

        $this->record('provision_cancelled', $slug);
    }

    /**
     * The slug arrives as a plain Livewire argument, so the row is looked up
     * here rather than trusted from the rendered list.
     */
    private function authorizedProvision(string $slug): ?TenantProvision
    {
        $this->authorize('update', Numerosis::model(Tenant::class));

        $provision = Numerosis::model(TenantProvision::class)::find($slug);

        return $provision instanceof TenantProvision ? $provision : null;
    }

    private function record(string $action, string $slug): void
    {
        unset($this->provisions);

        $message = __("numerosis::staff.logged.{$action}", ['slug' => $slug]);

        activity()
            ->causedBy(GetAuthenticatedUser::run())
            ->withProperties(['slug' => $slug])
            ->log(is_string($message) ? $message : $action);

        $this->dispatch('notify', type: 'success', message: $message);
    }
}; ?>
<section class="mx-auto w-full max-w-5xl">
    <x-slot:title>{{ __('numerosis::staff.provisions.heading') }}</x-slot:title>

    <div class="flex flex-col gap-6">
        <div>
            <x-numerosis::ui.heading :level="1">{{ __('numerosis::staff.provisions.heading') }}</x-numerosis::ui.heading>
            <x-numerosis::ui.subheading class="mt-1">{{ __('numerosis::staff.provisions.subheading') }}</x-numerosis::ui.subheading>
        </div>

        <flux:select wire:model.live="status" class="max-w-48">
            <flux:select.option value="all">{{ __('numerosis::staff.filters.all') }}</flux:select.option>
            @foreach (TenantProvisionStatus::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->value }}</flux:select.option>
            @endforeach
        </flux:select>

        <x-numerosis::ui.card>
            @if ($this->provisions->isEmpty())
                <x-numerosis::ui.empty-state :title="__('numerosis::staff.provisions.empty')"/>
            @else
                <flux:table :paginate="$this->provisions">
                    <flux:table.columns>
                        <flux:table.column>{{ __('numerosis::staff.provisions.columns.slug') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.provisions.columns.status') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.provisions.columns.steps') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.provisions.columns.attempts') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.provisions.columns.started') }}</flux:table.column>
                        <flux:table.column/>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->provisions as $provision)
                            <flux:table.row :key="$provision->slug">
                                <flux:table.cell>
                                    <flux:link :href="route('staff.provisions.show', $provision->slug)" wire:navigate>
                                        {{ $provision->slug }}
                                    </flux:link>
                                    @if ($provision->error !== null)
                                        <div class="text-xs text-danger-text">{{ $provision->error }}</div>
                                    @endif
                                </flux:table.cell>

                                <flux:table.cell>
                                    <flux:badge size="sm">{{ $provision->status->value }}</flux:badge>
                                </flux:table.cell>

                                <flux:table.cell>{{ $provision->currentStepLabel() }}</flux:table.cell>

                                <flux:table.cell>{{ $provision->failedAttempts() ?? '—' }}</flux:table.cell>

                                <flux:table.cell>{{ $provision->provisioning_started_at?->diffForHumans() ?? '—' }}</flux:table.cell>

                                <flux:table.cell class="text-right">
                                    @if ($provision->status !== TenantProvisionStatus::Completed)
                                        <flux:button size="sm" variant="filled" wire:click="retry('{{ $provision->slug }}')">
                                            {{ __('numerosis::staff.actions.retry') }}
                                        </flux:button>

                                        <flux:modal.trigger :name="'cancel-'.$provision->slug">
                                            <flux:button size="sm" variant="danger">
                                                {{ __('numerosis::staff.actions.cancel') }}
                                            </flux:button>
                                        </flux:modal.trigger>

                                        <flux:modal :name="'cancel-'.$provision->slug" class="max-w-md">
                                            <div class="space-y-4">
                                                <flux:heading size="lg">
                                                    {{ __('numerosis::staff.confirm.cancel_provision', ['slug' => $provision->slug]) }}
                                                </flux:heading>

                                                <div class="flex justify-end gap-2">
                                                    <flux:modal.close>
                                                        <flux:button variant="filled">
                                                            {{ __('numerosis::staff.actions.never_mind') }}
                                                        </flux:button>
                                                    </flux:modal.close>

                                                    <flux:button variant="danger" wire:click="cancel('{{ $provision->slug }}')">
                                                        {{ __('numerosis::staff.actions.confirm') }}
                                                    </flux:button>
                                                </div>
                                            </div>
                                        </flux:modal>
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
