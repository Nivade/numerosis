@props(['subscription'])

{{--
    The grace-period warning, before suspension bites. Distinct from
    ⚡suspended: this shows while the tenant still works, so the owner has a
    chance to fix it before access is paused. An unsettled payment is a badge
    on a working tenant, not a waiting room.
--}}
@if($subscription?->pastDue())
    <div {{ $attributes->merge(['class' => 'flex items-start gap-3 rounded-lg bg-warning-bg border border-warning-border px-4 py-3']) }}>
        <flux:icon.exclamation-triangle class="size-5 text-warning-icon shrink-0 mt-0.5" />
        <div class="min-w-0">
            <x-numerosis::ui.text size="sm" class="text-warning-text font-medium">
                Your last payment failed
            </x-numerosis::ui.text>
            <x-numerosis::ui.text size="xs" class="text-warning-text mt-0.5">
                Update your payment method to avoid your workspace being paused.
            </x-numerosis::ui.text>
        </div>
    </div>
@endif
