<?php

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Config;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Numerosis;

new #[Layout('numerosis-layouts::staff')]
class extends Component
{
    use WithPagination;

    /**
     * @return LengthAwarePaginator<int, Subscription>
     */
    #[Computed]
    public function subscriptions(): LengthAwarePaginator
    {
        return Numerosis::model(Subscription::class)::query()
            ->with('paymentPlan')
            ->orderByDesc('created_at')
            ->paginate(25);
    }

    /**
     * Stripe serves test-mode objects under a `/test` segment, and the live
     * dashboard 404s on a test-mode id.
     */
    public function stripeUrl(string $stripeId): string
    {
        $segment = str_starts_with(Config::string('cashier.secret', ''), 'sk_test') ? 'test/' : '';

        return "https://dashboard.stripe.com/{$segment}subscriptions/{$stripeId}";
    }
}; ?>
<section class="mx-auto w-full max-w-5xl">
    <x-slot:title>{{ __('numerosis::staff.subscriptions.heading') }}</x-slot:title>

    <div class="flex flex-col gap-6">
        <div>
            <x-numerosis::ui.heading :level="1">{{ __('numerosis::staff.subscriptions.heading') }}</x-numerosis::ui.heading>
            <x-numerosis::ui.subheading class="mt-1">{{ __('numerosis::staff.subscriptions.subheading') }}</x-numerosis::ui.subheading>
        </div>

        <x-numerosis::ui.card>
            @if ($this->subscriptions->isEmpty())
                <x-numerosis::ui.empty-state :title="__('numerosis::staff.subscriptions.empty')"/>
            @else
                <flux:table :paginate="$this->subscriptions">
                    <flux:table.columns>
                        <flux:table.column>{{ __('numerosis::staff.subscriptions.columns.tenant') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.subscriptions.columns.plan') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.subscriptions.columns.status') }}</flux:table.column>
                        <flux:table.column>{{ __('numerosis::staff.subscriptions.columns.ends') }}</flux:table.column>
                        <flux:table.column/>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->subscriptions as $subscription)
                            <flux:table.row :key="$subscription->getKey()">
                                <flux:table.cell>{{ $subscription->subscribable_id ?? '—' }}</flux:table.cell>

                                <flux:table.cell>
                                    {{ $subscription->paymentPlan?->name ?? $subscription->stripe_price ?? '—' }}
                                </flux:table.cell>

                                <flux:table.cell>
                                    <flux:badge size="sm">{{ $subscription->stripe_status }}</flux:badge>
                                </flux:table.cell>

                                <flux:table.cell>{{ $subscription->ends_at?->toFormattedDateString() ?? '—' }}</flux:table.cell>

                                <flux:table.cell class="text-right">
                                    <flux:button
                                        tag="a"
                                        size="sm"
                                        variant="filled"
                                        target="_blank"
                                        rel="noopener"
                                        href="{{ $this->stripeUrl($subscription->stripe_id) }}"
                                    >
                                        {{ __('numerosis::staff.actions.open_in_stripe') }}
                                    </flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-numerosis::ui.card>
    </div>
</section>
