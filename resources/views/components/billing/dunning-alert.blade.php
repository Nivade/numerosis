@props(['subscription'])

{{--
    Retry-schedule detail for the owner, alongside payment-status-banner's
    shorter warning. Deliberately doesn't fetch Stripe's next_payment_attempt
    live (that field lives on the Invoice, not the local Subscription row) —
    a page render is the wrong place for a synchronous Stripe API call, and
    Stripe's own dunning emails already carry the exact retry date.
--}}
@if($subscription?->pastDue())
    <div {{ $attributes->merge(['class' => 'rounded-lg border border-gray-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-4 py-3']) }}>
        <x-numerosis::ui.text size="sm" class="font-medium">
            What happens next
        </x-numerosis::ui.text>
        <x-numerosis::ui.text size="xs" variant="muted" class="mt-1">
            Stripe will automatically retry the payment a few times over the coming days.
            If it keeps failing, this workspace will be paused until the payment method is fixed —
            your data is never deleted while that happens.
        </x-numerosis::ui.text>
    </div>
@endif
