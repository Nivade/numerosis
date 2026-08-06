{{--
    A badge on an otherwise-working tenant, not a waiting room — the tenant
    stays fully usable while this shows. See custom-checkout.md,
    "Provisioning and settlement". Unreachable for cards in practice; only
    an async method (SEPA via iDEAL/Bancontact) settles over days rather
    than seconds.
--}}
<div {{ $attributes->merge(['class' => 'flex items-center gap-3 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 px-4 py-3']) }}>
    <flux:icon.clock class="size-5 text-amber-600 dark:text-amber-400 shrink-0" />
    <div class="min-w-0">
        <x-numerosis::ui.text size="sm" class="text-amber-800 dark:text-amber-300 font-medium">
            {{ __('numerosis::billing.awaiting_payment.title') }}
        </x-numerosis::ui.text>
        <x-numerosis::ui.text size="xs" class="text-amber-700 dark:text-amber-400 mt-0.5">
            {{ __('numerosis::billing.awaiting_payment.description') }}
        </x-numerosis::ui.text>
    </div>
</div>
