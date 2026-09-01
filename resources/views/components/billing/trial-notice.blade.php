@props([
    'plan',
    'billingCycle',
    'price',
])

@php
    /** @var \Nvade\Numerosis\Contracts\Billing\Plan $plan */
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
            <x-numerosis::ui.text variant="muted" size="sm">
                Free trial
            </x-numerosis::ui.text>
            <x-numerosis::ui.text variant="default" size="sm" class="font-medium">
                {{ $trialDays }} {{ Str::plural('day', $trialDays) }}
            </x-numerosis::ui.text>
        </div>
        <div class="flex items-center justify-between">
            <x-numerosis::ui.text variant="muted" size="sm">
                Then, billed {{ $billingCycle === \Nvade\Numerosis\Enums\Billing\BillingCycle::Monthly ? 'monthly' : 'yearly' }}
            </x-numerosis::ui.text>
            <x-numerosis::ui.text variant="default" size="sm" class="font-medium">
                {{ $price }}{{ $billingCycle->label() }}
            </x-numerosis::ui.text>
        </div>
    </div>
@endif
