@props([
    'billingCycle',
    'maxSavings' => 0,
])

<div {{ $attributes->merge(['class' => 'flex justify-center']) }}>
    <div class="relative flex p-1 bg-gray-100 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-inner">
        <button
            type="button"
            wire:click="$set('billingCycle', '{{ \App\Enums\BillingCycle::Monthly->value }}')"
            @class([
                'relative py-2.5 px-8 text-sm font-bold transition-all duration-300 rounded-lg z-10',
                'bg-white dark:bg-gray-700 shadow-md text-blue-600 dark:text-blue-400' => ($billingCycle instanceof \App\Enums\BillingCycle ? $billingCycle->value : $billingCycle) === \App\Enums\BillingCycle::Monthly->value,
                'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => ($billingCycle instanceof \App\Enums\BillingCycle ? $billingCycle->value : $billingCycle) !== \App\Enums\BillingCycle::Monthly->value,
            ])
        >
            Monthly
        </button>
        <button
            type="button"
            wire:click="$set('billingCycle', '{{ \App\Enums\BillingCycle::Yearly->value }}')"
            @class([
                'relative py-2.5 px-8 text-sm font-bold transition-all duration-300 rounded-lg z-10',
                'bg-white dark:bg-gray-700 shadow-md text-blue-600 dark:text-blue-400' => ($billingCycle instanceof \App\Enums\BillingCycle ? $billingCycle->value : $billingCycle) === \App\Enums\BillingCycle::Yearly->value,
                'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => ($billingCycle instanceof \App\Enums\BillingCycle ? $billingCycle->value : $billingCycle) !== \App\Enums\BillingCycle::Yearly->value,
            ])
        >
            Yearly
            @if($maxSavings > 0)
                <span @class([
                    'absolute -top-2.5 -right-4 px-2 py-0.5 text-[10px] font-black rounded-full shadow-sm border border-green-600/20',
                    'bg-green-500 text-white' => ($billingCycle instanceof \App\Enums\BillingCycle ? $billingCycle->value : $billingCycle) === \App\Enums\BillingCycle::Yearly->value,
                    'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' => ($billingCycle instanceof \App\Enums\BillingCycle ? $billingCycle->value : $billingCycle) !== \App\Enums\BillingCycle::Yearly->value,
                ])>
                    Save {{ $maxSavings }}%
                </span>
            @endif
        </button>
    </div>
</div>
