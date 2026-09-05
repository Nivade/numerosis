<div class="space-y-8">
    <x-numerosis::registration.header
        icon="shield-check"
        title="Complete Your Subscription"
        description="One last step — add a payment method to activate your workspace."
    />

    @if($plan && $domain)
        <div class="grid gap-6 lg:grid-cols-5 items-start">
            <x-numerosis::billing.order-summary :plan="$plan" :billing-cycle="$billingCycle" :domain="$domain" :custom-domain="$customDomain" class="lg:col-span-2" />

            <div class="lg:col-span-3">
                <livewire:billing.checkout :domain="$domain" :embedded="true" :key="'checkout-'.$domain" />
            </div>
        </div>
    @endif

    <div @class([
        'flex justify-start border-t border-zinc-200 dark:border-zinc-700',
        'pt-8' => $plan && $domain,
        'pt-4' => ! ($plan && $domain),
    ])>
        <flux:button wire:click="back" variant="outline" icon="chevron-left">
            Back
        </flux:button>
    </div>
</div>
