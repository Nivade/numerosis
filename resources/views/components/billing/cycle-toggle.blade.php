@props([
    'billingCycle',
    'maxSavings' => 0,
])

<div {{ $attributes->merge(['class' => 'flex justify-center']) }}>
    <div class="relative flex p-1 bg-zinc-100 dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-inner">
        <button
            type="button"
            wire:click="$set('billingCycle', '{{ \Nvade\Numerosis\Enums\BillingCycle::Monthly->value }}')"
            @class([
                'relative py-2.5 px-8 text-sm font-bold transition-all duration-300 rounded-lg z-10',
                'bg-white dark:bg-zinc-700 shadow-md text-primary' => ($billingCycle instanceof \Nvade\Numerosis\Enums\BillingCycle ? $billingCycle->value : $billingCycle) === \Nvade\Numerosis\Enums\BillingCycle::Monthly->value,
                'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' => ($billingCycle instanceof \Nvade\Numerosis\Enums\BillingCycle ? $billingCycle->value : $billingCycle) !== \Nvade\Numerosis\Enums\BillingCycle::Monthly->value,
            ])
        >
            Monthly
        </button>
        <button
            type="button"
            wire:click="$set('billingCycle', '{{ \Nvade\Numerosis\Enums\BillingCycle::Yearly->value }}')"
            @class([
                'relative py-2.5 px-8 text-sm font-bold transition-all duration-300 rounded-lg z-10',
                'bg-white dark:bg-zinc-700 shadow-md text-primary' => ($billingCycle instanceof \Nvade\Numerosis\Enums\BillingCycle ? $billingCycle->value : $billingCycle) === \Nvade\Numerosis\Enums\BillingCycle::Yearly->value,
                'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' => ($billingCycle instanceof \Nvade\Numerosis\Enums\BillingCycle ? $billingCycle->value : $billingCycle) !== \Nvade\Numerosis\Enums\BillingCycle::Yearly->value,
            ])
        >
            Yearly
            @if($maxSavings > 0)
                <span @class([
                    'absolute -top-2.5 -right-4 px-2 py-0.5 text-[10px] font-black rounded-full shadow-sm border border-success-border',
                    'bg-success-icon text-white' => ($billingCycle instanceof \Nvade\Numerosis\Enums\BillingCycle ? $billingCycle->value : $billingCycle) === \Nvade\Numerosis\Enums\BillingCycle::Yearly->value,
                    'bg-success-bg text-success-text' => ($billingCycle instanceof \Nvade\Numerosis\Enums\BillingCycle ? $billingCycle->value : $billingCycle) !== \Nvade\Numerosis\Enums\BillingCycle::Yearly->value,
                ])>
                    Save {{ $maxSavings }}%
                </span>
            @endif
        </button>
    </div>
</div>
