@props([
    'plan',
    'billingCycle',
    'domain',
])

@php
    /** @var \App\Models\Central\PaymentPlan $plan */
    /** @var \App\Enums\BillingCycle $billingCycle */
    $billing = resolve(\App\Services\Billing\BillingService::class);
    $price = $billing->formatAmount($plan->getPrice($billingCycle));
    $trialDays = $plan->trialDays();
    $onTrial = $trialDays !== null && $trialDays > 0;
    $dueToday = $onTrial ? $billing->formatAmount(0) : $price;
@endphp

{{--
    A receipt, not a settings panel — the vernacular a customer already
    reads as "this is what I'm paying for". The dashed rules and the
    gradient total are the one deliberate flourish on this step; everything
    else stays quiet around it.
--}}
<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-2xl border border-gray-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm']) }}>
    <div class="h-1.5 bg-linear-to-r from-blue-600 to-purple-600"></div>

    <div class="p-6 space-y-4">
        <x-ui.text variant="subtle" size="xs" class="uppercase tracking-wider font-semibold">
            Order summary
        </x-ui.text>

        <div>
            <x-ui.text variant="default" size="base" class="font-bold">
                {{ $plan->name }} plan
            </x-ui.text>
            <x-ui.text variant="subtle" size="xs" class="mt-1 truncate">
                {{ $domain }}.{{ config('app.domain') }}
            </x-ui.text>
        </div>

        <div class="border-t border-dashed border-gray-300 dark:border-zinc-700"></div>

        @if($onTrial)
            <x-billing.trial-notice :plan="$plan" :billing-cycle="$billingCycle" :price="$price" />

            <div class="border-t border-dashed border-gray-300 dark:border-zinc-700"></div>
        @endif

        <div class="flex items-end justify-between gap-3">
            <x-ui.text variant="default" size="sm" class="font-semibold">
                Due today
            </x-ui.text>
            <span class="text-2xl font-black text-transparent bg-clip-text bg-linear-to-r from-blue-600 to-purple-600 tabular-nums">
                {{ $dueToday }}
            </span>
        </div>

        <x-ui.text variant="subtle" size="xs">
            @if($onTrial)
                You won't be charged until {{ now()->addDays($trialDays)->format('M j, Y') }}. Cancel anytime before then.
            @else
                Cancel anytime from your billing settings.
            @endif
        </x-ui.text>
    </div>
</div>
