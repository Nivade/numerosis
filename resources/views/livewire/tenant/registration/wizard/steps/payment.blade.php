<div class="space-y-8">
    <x-registration.header
        icon="shield-check"
        title="Complete Your Subscription"
        description="One last step — add a payment method to activate your workspace."
    />

    @if($plan && $domain)
        <div class="grid gap-6 lg:grid-cols-5 items-start">
            <x-billing.order-summary :plan="$plan" :billing-cycle="$billingCycle" :domain="$domain" class="lg:col-span-2" />

            <div class="lg:col-span-3">
                <livewire:billing.checkout :domain="$domain" :embedded="true" :key="'checkout-'.$domain" />
            </div>
        </div>

        <div class="flex justify-start pt-8 border-t border-gray-200 dark:border-zinc-700">
            <flux:button wire:click="back" variant="outline" icon="chevron-left">
                Back
            </flux:button>
        </div>
    @else
        <div class="flex justify-start pt-4 border-t border-gray-200 dark:border-zinc-700">
            <flux:button wire:click="back" variant="outline" icon="chevron-left">
                Back
            </flux:button>
        </div>
    @endif
</div>
