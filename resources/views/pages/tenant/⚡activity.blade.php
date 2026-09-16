<?php

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Nvade\Numerosis\Actions\Queries\ReadActivityLog;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\Tenant;

new #[Layout('numerosis-layouts::app')]
class extends Component
{
    use WithPagination;

    #[Url]
    public string $actor = '';

    #[Url]
    public string $since = '';

    public function mount(): void
    {
        $this->authorize('viewActivity', Membership::class);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, \Nvade\Numerosis\Models\Activity>
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        $tenant = tenant();

        abort_unless($tenant instanceof Tenant, 404);

        return ReadActivityLog::forTenant($tenant)
            ->when($this->actor !== '', fn (Builder $query) => $query->where('properties->actor', $this->actor))
            ->when($this->since !== '', fn (Builder $query) => $query->where('created_at', '>=', $this->since))
            ->orderByDesc('id')
            ->paginate(20);
    }
}; ?>
<section class="mx-auto max-w-prose w-full">
    <x-slot:title>{{ __('Activity') }}</x-slot:title>

    <div class="flex w-full flex-1 flex-col gap-4">
        <div>
            <x-numerosis::ui.heading :level="1">{{ __('Activity') }}</x-numerosis::ui.heading>
            <x-numerosis::ui.subheading class="mt-1">{{ __('What has happened in this team') }}</x-numerosis::ui.subheading>
        </div>

        <div class="flex flex-wrap gap-3">
            <flux:select wire:model.live="actor" class="max-w-40">
                <flux:select.option value="">{{ __('Anyone') }}</flux:select.option>
                <flux:select.option value="user">{{ __('A member') }}</flux:select.option>
                <flux:select.option value="staff">{{ __('Support') }}</flux:select.option>
                <flux:select.option value="system">{{ __('The system') }}</flux:select.option>
            </flux:select>

            <flux:input wire:model.live="since" type="date" class="max-w-44"/>
        </div>

        <x-numerosis::ui.card>
            @if ($this->entries->isEmpty())
                <x-numerosis::ui.empty-state :title="__('Nothing has been recorded yet.')"/>
            @else
                <flux:table :paginate="$this->entries">
                    <flux:table.columns>
                        <flux:table.column>{{ __('When') }}</flux:table.column>
                        <flux:table.column>{{ __('What') }}</flux:table.column>
                        <flux:table.column>{{ __('Who') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->entries as $entry)
                            <flux:table.row :key="$entry->getKey()">
                                <flux:table.cell>{{ $entry->created_at?->diffForHumans() ?? '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $entry->description }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm">{{ $entry->getProperty('actor', 'system') }}</flux:badge>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-numerosis::ui.card>
    </div>
</section>
