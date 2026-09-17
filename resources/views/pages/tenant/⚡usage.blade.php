<?php

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Actions\Queries\GetTenantUsage;
use Nvade\Numerosis\Data\Billing\MeterUsage;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Current-period usage against what the plan includes, read off the local
 * counter so the number here is the number the meter reports.
 */
new #[Layout('numerosis-layouts::app')]
class extends Component
{
    /**
     * @return Collection<int, MeterUsage>
     */
    #[Computed]
    public function meters(): Collection
    {
        $tenant = tenant();

        return $tenant instanceof Tenant ? GetTenantUsage::run($tenant) : new Collection;
    }
};
?>
<div class="space-y-6">
    <div class="space-y-1">
        <flux:heading size="xl">{{ __('Usage') }}</flux:heading>
        <flux:subheading>
            {{ __('What this workspace has used in the period being billed right now.') }}
        </flux:subheading>
    </div>

    @if ($this->meters->isEmpty())
        <flux:callout icon="chart-bar">
            <flux:callout.heading>{{ __('Nothing metered') }}</flux:callout.heading>
            <flux:callout.text>{{ __('This plan bills a flat price, so there is no usage to report.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($this->meters as $meter)
                <flux:card class="space-y-3">
                    <div class="flex items-baseline justify-between">
                        <flux:heading>{{ $meter->key }}</flux:heading>
                        <span class="text-2xl font-semibold tabular-nums">{{ number_format($meter->used) }}</span>
                    </div>

                    @if ($meter->included !== null)
                        <flux:text size="sm">
                            {{ __(':used of :included included', ['used' => number_format($meter->used), 'included' => number_format($meter->included)]) }}
                        </flux:text>

                        @if ($meter->overage() > 0)
                            <flux:badge color="amber" size="sm">
                                {{ __(':count over the included allowance', ['count' => number_format($meter->overage())]) }}
                            </flux:badge>
                        @else
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                {{ __(':count left this period', ['count' => number_format((int) $meter->remaining())]) }}
                            </flux:text>
                        @endif
                    @else
                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                            {{ __('Billed per unit, with no included allowance.') }}
                        </flux:text>
                    @endif

                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                        {{ __('Period :start to :end', [
                            'start' => $meter->period->start->toFormattedDayDateString(),
                            'end' => $meter->period->end->toFormattedDayDateString(),
                        ]) }}
                    </flux:text>
                </flux:card>
            @endforeach
        </div>

        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
            {{ __('Usage over the included allowance is added to the next invoice.') }}
        </flux:text>
    @endif
</div>
