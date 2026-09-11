@use(\Nvade\Numerosis\Enums\Billing\BillingCycle)
@use(\Nvade\Numerosis\Enums\Tenancy\IdentificationMode)
@use(\Nvade\Numerosis\Models\Central\PaymentPlan)
@use(\Nvade\Numerosis\Services\Billing\BillingService)
@props([
    'plan',
    'billingCycle',
    'domain',
    'customDomain' => null,
])

@php
    /** @var PaymentPlan $plan */
    /** @var BillingCycle $billingCycle */
    $billing = resolve(BillingService::class);
    $price = $billing->formatAmount($plan->getPrice($billingCycle));
    $trialDays = $plan->trialDays();
    $onTrial = $trialDays !== null && $trialDays > 0;
    $dueToday = $onTrial ? $billing->formatAmount(0) : $price;
    $mode = IdentificationMode::current();
@endphp

{{--
    A receipt, not a settings panel — the vernacular a customer already
    reads as "this is what I'm paying for". The dashed rules and the
    gradient total are the one deliberate flourish on this step; everything
    else stays quiet around it.
--}}
<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm']) }}>
    <div class="h-1.5 bg-linear-to-r from-blue-600 to-purple-600"></div>

    <div class="p-6 space-y-4">
        <x-numerosis::ui.text variant="subtle" size="xs" class="uppercase tracking-wider font-semibold">
            Order summary
        </x-numerosis::ui.text>

        <div>
            <x-numerosis::ui.text variant="default" size="base" class="font-bold">
                {{ $plan->name }} plan
            </x-numerosis::ui.text>
            <x-numerosis::ui.text variant="subtle" size="xs" class="mt-1 truncate">
                @if($mode === IdentificationMode::Subdomain)
                    {{ $domain }}.{{ config('numerosis.domains.apex') }}
                @elseif($mode === IdentificationMode::CustomDomain)
                    {{ $customDomain }}
                @else
                    {{ config('numerosis.domains.central') }}/{{ $domain }}
                @endif
            </x-numerosis::ui.text>
        </div>

        <div class="border-t border-dashed border-zinc-300 dark:border-zinc-700"></div>

        @if($onTrial)
            <x-numerosis::billing.trial-notice :plan="$plan" :billing-cycle="$billingCycle" :price="$price" />

            <div class="border-t border-dashed border-zinc-300 dark:border-zinc-700"></div>
        @endif

        <div class="flex items-end justify-between gap-3">
            <x-numerosis::ui.text variant="default" size="sm" class="font-semibold">
                Due today
            </x-numerosis::ui.text>
            <span class="text-2xl font-black text-transparent bg-clip-text bg-linear-to-r from-blue-600 to-purple-600 tabular-nums">
                {{ $dueToday }}
            </span>
        </div>

        <x-numerosis::ui.text variant="subtle" size="xs">
            @if($onTrial)
                You won't be charged until {{ now()->addDays($trialDays)->format('M j, Y') }}. Cancel anytime before then.
            @else
                Cancel anytime from your billing settings.
            @endif
        </x-numerosis::ui.text>
    </div>
</div>
