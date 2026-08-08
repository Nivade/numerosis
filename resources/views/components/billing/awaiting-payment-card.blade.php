{{--
    A badge on an otherwise-working tenant, not a waiting room — the tenant
    stays fully usable while this shows. See custom-checkout.md,
    "Provisioning and settlement". Unreachable for cards in practice; only
    an async method (SEPA via iDEAL/Bancontact) settles over days rather
    than seconds.
--}}
<div {{ $attributes->merge(['class' => 'flex items-center gap-3 rounded-lg bg-warning-bg border border-warning-border px-4 py-3']) }}>
    <flux:icon.clock class="size-5 text-warning-icon shrink-0" />
    <div class="min-w-0">
        <x-numerosis::ui.text size="sm" class="text-warning-text font-medium">
            {{ __('numerosis::billing.awaiting_payment.title') }}
        </x-numerosis::ui.text>
        <x-numerosis::ui.text size="xs" class="text-warning-text mt-0.5">
            {{ __('numerosis::billing.awaiting_payment.description') }}
        </x-numerosis::ui.text>
    </div>
</div>
