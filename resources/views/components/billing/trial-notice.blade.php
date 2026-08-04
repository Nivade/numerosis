@props([
    'plan',
    'billingCycle',
    'price',
])

@php
    /** @var \App\Contracts\Billing\Plan $plan */
    $trialDays = $plan->trialDays();
@endphp

{{--
    "Free until when, then how much" is the single most-disputed line in any
    signup flow — one source of truth, reused wherever a plan's price is
    shown before payment. Renders as receipt rows, not a boxed callout, so
    it reads as one line item among others rather than a separate warning.
--}}
@if($trialDays !== null && $trialDays > 0)
    <div {{ $attributes->merge(['class' => 'space-y-2']) }}>
        <div class="flex items-center justify-between">
            <x-ui.text variant="muted" size="sm">
                Free trial
            </x-ui.text>
            <x-ui.text variant="default" size="sm" class="font-medium">
                {{ $trialDays }} {{ Str::plural('day', $trialDays) }}
            </x-ui.text>
        </div>
        <div class="flex items-center justify-between">
            <x-ui.text variant="muted" size="sm">
                Then, billed {{ $billingCycle === \App\Enums\BillingCycle::Monthly ? 'monthly' : 'yearly' }}
            </x-ui.text>
            <x-ui.text variant="default" size="sm" class="font-medium">
                {{ $price }}{{ $billingCycle->label() }}
            </x-ui.text>
        </div>
    </div>
@endif
