<?php

use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Nvade\Numerosis\Models\Central\TenantMigrationRun;
use Nvade\Numerosis\Numerosis;

new #[Layout('numerosis-layouts::staff')]
class extends Component
{
    #[Url]
    public string $run = '';

    /**
     * The twenty most recent runs. A rollout is an event, not a stream, so
     * there is no pagination here.
     *
     * @return list<string>
     */
    #[Computed]
    public function runIds(): array
    {
        $ids = Numerosis::model(TenantMigrationRun::class)::query()
            ->groupBy('run_id')
            ->orderByRaw('max(created_at) desc')
            ->limit(20)
            ->pluck('run_id')
            ->all();

        return array_values(array_filter($ids, is_string(...)));
    }

    public function currentRun(): ?string
    {
        $ids = $this->runIds;

        if (in_array($this->run, $ids, true)) {
            return $this->run;
        }

        return $ids[0] ?? null;
    }

    /**
     * @return Collection<int, TenantMigrationRun>
     */
    #[Computed]
    public function legs(): Collection
    {
        $run = $this->currentRun();

        if ($run === null) {
            return new Collection;
        }

        return Numerosis::model(TenantMigrationRun::class)::query()
            ->where('run_id', $run)
            ->failedFirst()
            ->get();
    }

    #[Computed]
    public function failureCount(): int
    {
        return $this->legs->filter(fn (TenantMigrationRun $leg): bool => $leg->hasFailed())->count();
    }
}; ?>
@use(Nvade\Numerosis\Enums\Tenancy\MigrationRunStatus)
<section class="mx-auto w-full max-w-5xl">
    <x-slot:title>{{ __('numerosis::staff.migrations.heading') }}</x-slot:title>

    <div class="flex flex-col gap-6">
        <div>
            <x-numerosis::ui.heading :level="1">{{ __('numerosis::staff.migrations.heading') }}</x-numerosis::ui.heading>
            <x-numerosis::ui.subheading class="mt-1">{{ __('numerosis::staff.migrations.subheading') }}</x-numerosis::ui.subheading>
        </div>

        @if ($this->runIds !== [])
            <flux:select wire:model.live="run" class="max-w-md">
                @foreach ($this->runIds as $runId)
                    <flux:select.option value="{{ $runId }}">{{ $runId }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <x-numerosis::ui.card>
            @if ($this->legs->isEmpty())
                <x-numerosis::ui.empty-state :title="__('numerosis::staff.migrations.empty')"/>
            @else
                @if ($this->failureCount > 0)
                    <flux:callout variant="danger" class="mb-4">
                        {{ __('numerosis::staff.migrations.failures', ['count' => $this->failureCount]) }}
                    </flux:callout>
                @endif

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('numerosis::staff.migrations.columns.tenant') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.provisions.columns.status') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.migrations.columns.applied') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.provision.columns.duration') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->legs as $leg)
                            <flux:table.row :key="$leg->id">
                                <flux:table.cell>
                                    <flux:link :href="route('staff.tenants.show', $leg->tenant_id)" wire:navigate>
                                        {{ $leg->tenant_id }}
                                    </flux:link>
                                    @if ($leg->error !== null)
                                        <div class="text-xs text-danger-text">{{ $leg->error }}</div>
                                    @endif
                                </flux:table.cell>

                                <flux:table.cell>
                                    <flux:badge size="sm" :color="$leg->status === MigrationRunStatus::Failed ? 'red' : 'zinc'">
                                        {{ $leg->status->value }}
                                    </flux:badge>
                                </flux:table.cell>

                                <flux:table.cell>
                                    @forelse ($leg->migrations ?? [] as $migration)
                                        <div class="text-xs">{{ $migration }}</div>
                                    @empty
                                        —
                                    @endforelse
                                </flux:table.cell>

                                <flux:table.cell>
                                    {{ $leg->durationSeconds() === null ? '—' : $leg->durationSeconds().'s' }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-numerosis::ui.card>
    </div>
</section>
