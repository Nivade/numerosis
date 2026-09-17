<?php

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Nvade\Numerosis\Actions\Queries\ReadActivityLog;

new #[Layout('numerosis-layouts::staff')]
class extends Component
{
    use WithPagination;

    #[Url]
    public string $tenant = '';

    #[Url]
    public string $actor = '';

    #[Url]
    public string $causer = '';

    #[Url]
    public string $since = '';

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
        return ReadActivityLog::run()
            ->when($this->tenant !== '', fn (Builder $query) => $query->where('subject_id', $this->tenant))
            ->when($this->actor !== '', fn (Builder $query) => $query->where('properties->actor', $this->actor))
            ->when($this->causer !== '', fn (Builder $query) => $query->where('causer_id', $this->causer))
            ->when($this->since !== '', fn (Builder $query) => $query->where('created_at', '>=', $this->since))
            ->orderByDesc('id')
            ->paginate(25);
    }
}; ?>
<section class="mx-auto w-full max-w-5xl">
    <x-slot:title>{{ __('numerosis::staff.activity.heading') }}</x-slot:title>

    <div class="flex flex-col gap-6">
        <div>
            <x-numerosis::ui.heading :level="1">{{ __('numerosis::staff.activity.heading') }}</x-numerosis::ui.heading>
            <x-numerosis::ui.subheading class="mt-1">{{ __('numerosis::staff.activity.subheading') }}</x-numerosis::ui.subheading>
        </div>

        <div class="flex flex-wrap gap-3">
            <flux:input
                wire:model.live.debounce.300ms="tenant"
                class="max-w-xs"
                icon="building-office-2"
                :placeholder="__('numerosis::staff.activity.filters.tenant')"
            />

            <flux:select wire:model.live="actor" class="max-w-40">
                <flux:select.option value="">{{ __('numerosis::staff.filters.all') }}</flux:select.option>
                <flux:select.option value="user">{{ __('numerosis::staff.activity.actors.user') }}</flux:select.option>
                <flux:select.option value="staff">{{ __('numerosis::staff.activity.actors.staff') }}</flux:select.option>
                <flux:select.option value="system">{{ __('numerosis::staff.activity.actors.system') }}</flux:select.option>
            </flux:select>

            <flux:input
                wire:model.live.debounce.300ms="causer"
                class="max-w-xs"
                icon="user"
                :placeholder="__('numerosis::staff.activity.filters.causer')"
            />

            <flux:input wire:model.live="since" type="date" class="max-w-44"/>
        </div>

        <x-numerosis::ui.card>
            @if ($this->entries->isEmpty())
                <x-numerosis::ui.empty-state :title="__('numerosis::staff.activity.empty')"/>
            @else
                <flux:table :paginate="$this->entries">
                    <flux:table.columns>
                        <flux:table.column>{{ __('numerosis::staff.activity.columns.when') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.activity.columns.what') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.activity.columns.subject') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.activity.columns.actor') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->entries as $entry)
                            <flux:table.row :key="$entry->getKey()">
                                <flux:table.cell>{{ $entry->created_at?->diffForHumans() ?? '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $entry->description }}</flux:table.cell>
                                <flux:table.cell>
                                    {{ class_basename((string) $entry->subject_type) }} {{ $entry->subject_id }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm">{{ $entry->getProperty('actor', 'system') }}</flux:badge>
                                    {{ $entry->causer_id !== null ? '#'.$entry->causer_id : '' }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-numerosis::ui.card>
    </div>
</section>
