@props(['subscription'])

{{--
    The grace-period warning, before suspension bites. Distinct from
    ⚡suspended: this shows while the tenant still works, so the owner has a
    chance to fix it before access is paused — see custom-checkout.md,
    "AwaitingPayment is a badge on a working tenant, not a waiting room."
--}}
@if($subscription?->pastDue())
    <div {{ $attributes->merge(['class' => 'flex items-start gap-3 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 px-4 py-3']) }}>
        <flux:icon.exclamation-triangle class="size-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
        <div class="min-w-0">
            <x-ui.text size="sm" class="text-amber-800 dark:text-amber-300 font-medium">
                Your last payment failed
            </x-ui.text>
            <x-ui.text size="xs" class="text-amber-700 dark:text-amber-400 mt-0.5">
                Update your payment method to avoid your workspace being paused.
            </x-ui.text>
        </div>
    </div>
@endif
