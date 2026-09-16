<?php

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Actions\Queries\GetProvisionTimeline;
use Nvade\Numerosis\Data\Tenancy\StepTimelineEntry;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;

new #[Layout('numerosis-layouts::staff')]
class extends Component
{
    public TenantProvision $provision;

    public function mount(string $slug): void
    {
        $this->provision = Numerosis::model(TenantProvision::class)::findOrFail($slug);
    }

    /**
     * @return list<StepTimelineEntry>
     */
    #[Computed]
    public function timeline(): array
    {
        return GetProvisionTimeline::run($this->provision);
    }
}; ?>
@use(Nvade\Numerosis\Enums\Tenancy\StepOutcome)
<section class="mx-auto w-full max-w-5xl">
    <x-slot:title>{{ $provision->slug }}</x-slot:title>

    <div class="flex flex-col gap-6">
        <div>
            <flux:link :href="route('staff.provisions')" wire:navigate class="text-sm">
                {{ __('numerosis::staff.provision.back') }}
            </flux:link>

            <x-numerosis::ui.heading :level="1" class="mt-2">{{ $provision->slug }}</x-numerosis::ui.heading>
            <x-numerosis::ui.subheading class="mt-1">{{ $provision->name }}</x-numerosis::ui.subheading>
        </div>

        <x-numerosis::ui.card>
            <dl class="grid gap-2 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-zinc-500">{{ __('numerosis::staff.provisions.columns.status') }}</dt>
                    <dd><flux:badge size="sm">{{ $provision->status->value }}</flux:badge></dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('numerosis::staff.provisions.columns.started') }}</dt>
                    <dd>{{ $provision->provisioning_started_at?->diffForHumans() ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('numerosis::staff.provision.completed') }}</dt>
                    <dd>{{ $provision->completed_at?->diffForHumans() ?? '—' }}</dd>
                </div>
                @if ($provision->error !== null)
                    <div class="sm:col-span-3">
                        <dt class="text-zinc-500">{{ __('numerosis::staff.provisions.columns.error') }}</dt>
                        <dd class="text-danger-text">{{ $provision->error }}</dd>
                    </div>
                @endif
            </dl>
        </x-numerosis::ui.card>

        <x-numerosis::ui.card>
            <x-numerosis::ui.heading :level="2">{{ __('numerosis::staff.provision.timeline') }}</x-numerosis::ui.heading>

            <flux:table class="mt-2">
                <flux:table.columns>
                    <flux:table.column>{{ __('numerosis::staff.provision.columns.step') }}</flux:table.column>
                    <flux:table.column>{{ __('numerosis::staff.provision.columns.state') }}</flux:table.column>
                    <flux:table.column>{{ __('numerosis::staff.provisions.columns.attempts') }}</flux:table.column>
                    <flux:table.column>{{ __('numerosis::staff.provision.columns.duration') }}</flux:table.column>
                    <flux:table.column>{{ __('numerosis::staff.provision.columns.at') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->timeline as $entry)
                        <flux:table.row :key="$entry->step">
                            <flux:table.cell>
                                <div>{{ $entry->label }}</div>
                                @if ($entry->reason !== null)
                                    <div class="text-xs text-zinc-500">{{ $entry->reason }}</div>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                <flux:badge size="sm" :color="$entry->outcome === StepOutcome::Failed ? 'red' : 'zinc'">
                                    {{ $entry->outcome?->value ?? __('numerosis::staff.provision.pending') }}
                                </flux:badge>
                            </flux:table.cell>

                            <flux:table.cell>{{ $entry->attempts ?? '—' }}</flux:table.cell>

                            <flux:table.cell>
                                {{ $entry->durationSeconds === null ? '—' : $entry->durationSeconds.'s' }}
                            </flux:table.cell>

                            <flux:table.cell>{{ $entry->at?->diffForHumans() ?? '—' }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </x-numerosis::ui.card>
    </div>
</section>
