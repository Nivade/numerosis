@props(['pm', 'checked' => false])

@php
    // Stripe's own brand slugs. Not a trademark reproduction of any card
    // network's logo — a flat monogram chip in the network's associated
    // colour, the same idiom Stripe's own dashboard uses for card lists.
    $chip = match ($pm['brand']) {
        'visa' => ['label' => 'VISA', 'class' => 'bg-blue-600 text-white'],
        'mastercard' => ['label' => 'MC', 'class' => 'bg-orange-500 text-white'],
        'amex' => ['label' => 'AMEX', 'class' => 'bg-sky-700 text-white'],
        'discover' => ['label' => 'DISC', 'class' => 'bg-orange-400 text-white'],
        'diners' => ['label' => 'DINERS', 'class' => 'bg-zinc-700 text-white'],
        'jcb' => ['label' => 'JCB', 'class' => 'bg-emerald-600 text-white'],
        'unionpay' => ['label' => 'UP', 'class' => 'bg-red-600 text-white'],
        default => ['label' => null, 'class' => 'bg-zinc-200 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-400'],
    };
@endphp

<label class="group relative flex cursor-pointer items-center gap-4 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-4 transition-all hover:border-zinc-300 dark:hover:border-zinc-600 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50/60 dark:has-[:checked]:bg-blue-900/10 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-blue-500 has-[:focus-visible]:ring-offset-2">
    <input
        type="radio"
        name="pm-choice"
        x-model="selectedPaymentMethodId"
        value="{{ $pm['id'] }}"
        @change="mode = 'saved'"
        @if($checked) checked @endif
        class="sr-only"
    />

    <span @class([$chip['class'], 'flex h-8 w-11 shrink-0 items-center justify-center rounded-md text-[10px] font-bold tracking-wider'])>
        @if($chip['label'])
            {{ $chip['label'] }}
        @else
            <flux:icon.credit-card variant="micro" class="size-4" />
        @endif
    </span>

    <span class="min-w-0 flex-1">
        <span class="flex items-center gap-2">
            <span class="font-medium text-zinc-900 dark:text-zinc-100">
                {{ ucfirst($pm['brand']) }} &middot;&middot;&middot;&middot; {{ $pm['last4'] }}
            </span>
            @if($pm['isDefault'])
                <flux:badge size="sm">{{ __('numerosis::billing.checkout.default_payment_method') }}</flux:badge>
            @endif
        </span>
        <span class="block text-sm text-zinc-500 dark:text-zinc-400">
            {{ __('numerosis::billing.checkout.expires', ['month' => sprintf('%02d', $pm['expMonth']), 'year' => $pm['expYear']]) }}
        </span>
    </span>

    <span class="hidden size-5 shrink-0 items-center justify-center rounded-full bg-blue-500 group-has-[:checked]:flex">
        <flux:icon.check variant="micro" class="size-3.5 text-white" />
    </span>
</label>
