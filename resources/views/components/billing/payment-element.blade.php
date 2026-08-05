{{--
    Everything Stripe touches lives in this one component, on purpose — see
    custom-checkout.md, "Frontend". A Livewire re-render that reaches inside
    the wire:ignore'd mount div would destroy the iframe and whatever the
    customer typed, with no error shown. x-data lives on the parent wrapper
    (payment.blade.php) so the shared footer's Subscribe button can reach
    submit() too — this component only owns the mount point itself.
--}}
<div class="rounded-2xl border border-gray-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6 h-full">
    <div class="flex items-center gap-2 mb-4">
        <flux:icon.credit-card class="size-4 text-zinc-400" />
        <x-numerosis::ui.text variant="subtle" size="xs" class="uppercase tracking-wider font-semibold">
            Payment method
        </x-numerosis::ui.text>
    </div>

    {{-- Placeholder while Stripe.js loads and the Element mounts, so the panel never flashes empty. --}}
    <div x-show="!elementReady" x-cloak class="space-y-3" aria-hidden="true">
        <div class="h-11 rounded-lg bg-gray-100 dark:bg-zinc-800 animate-pulse"></div>
        <div class="grid grid-cols-2 gap-3">
            <div class="h-11 rounded-lg bg-gray-100 dark:bg-zinc-800 animate-pulse"></div>
            <div class="h-11 rounded-lg bg-gray-100 dark:bg-zinc-800 animate-pulse"></div>
        </div>
        <div class="h-11 rounded-lg bg-gray-100 dark:bg-zinc-800 animate-pulse"></div>
    </div>

    <div wire:ignore x-ref="paymentElement" x-show="elementReady" x-cloak></div>

    <div x-show="errorMessage" x-cloak class="mt-4 flex items-start gap-2 rounded-lg bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900 px-4 py-3">
        <flux:icon.exclamation-triangle class="size-4 text-red-600 dark:text-red-400 shrink-0 mt-0.5" />
        <x-numerosis::ui.text size="sm" class="text-red-700 dark:text-red-300" x-text="errorMessage"></x-numerosis::ui.text>
    </div>

    <x-numerosis::billing.secure-badge class="pt-5" />
</div>
