{{--
    Mounts in the same Stripe `elements` group as payment-element.blade.php,
    so confirmSetup() attaches this address to the PaymentMethod's
    billing_details automatically — nothing client-supplied to trust. See
    .claude/plans/module-marketplace.md, "Automatic tax, the billing address,
    and VAT numbers".
--}}
<div class="rounded-2xl border border-gray-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6 h-full">
    <div class="flex items-center gap-2 mb-4">
        <flux:icon.map-pin class="size-4 text-zinc-400" />
        <x-numerosis::ui.text variant="subtle" size="xs" class="uppercase tracking-wider font-semibold">
            Billing address
        </x-numerosis::ui.text>
    </div>

    <div x-show="!addressElementReady" x-cloak class="space-y-3" aria-hidden="true">
        <div class="h-11 rounded-lg bg-gray-100 dark:bg-zinc-800 animate-pulse"></div>
        <div class="h-11 rounded-lg bg-gray-100 dark:bg-zinc-800 animate-pulse"></div>
    </div>

    <div wire:ignore x-ref="addressElement" x-show="addressElementReady" x-cloak></div>

    <div class="mt-4">
        <flux:input
            wire:model="vatNumber"
            label="VAT number (optional)"
            placeholder="e.g. DE123456789"
        />
    </div>
</div>
