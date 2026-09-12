@use(\Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser)
@use(\Nvade\Numerosis\Services\Billing\BillingService)
<div class="space-y-8">
    <x-numerosis::registration.header
        icon="sparkles"
        title="Choose Your Plan"
        description="Select the plan that best fits your needs"
    />

    <!-- Plan Selection -->
    <div class="space-y-6">
        @php
            $maxSavings = $paymentPlans->map(fn($plan) => $plan->getSavingsPercentage())->max();
            $cycle = $this->cycle();
            $billing = resolve(BillingService::class);
        @endphp
        <x-numerosis::billing.cycle-toggle :billing-cycle="$cycle" :max-savings="$maxSavings" class="mb-8" />

        <flux:field>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                @foreach($paymentPlans as $paymentPlan)
                    <x-numerosis::billing.plan-card
                        :plan="$paymentPlan"
                        :billing-cycle="$cycle"
                        :price="$billing->formatAmount($paymentPlan->getPrice($cycle))"
                        :incentive="$paymentPlan->getIncentive($cycle)"
                        type="selectable"
                    />
                @endforeach
            </div>
            <flux:error name="payment_plan"/>
        </flux:field>
    </div>

    <!-- Terms and Conditions -->
    <div class="border-t border-zinc-200 dark:border-zinc-700 pt-6">
        <flux:checkbox
            wire:model.live="terms"
            label="I agree to the Terms and Conditions *"
            class="font-medium"
        >
            <x-slot name="description">
                By registering, you agree to our
                <a href="#" class="text-primary hover:text-primary-hover font-medium underline">Terms of Service</a>
                and
                <a href="#" class="text-primary hover:text-primary-hover font-medium underline">Privacy Policy</a>
            </x-slot>
        </flux:checkbox>
        <flux:error name="terms"/>
    </div>

    <x-numerosis::billing.payment-error :message="$checkoutError" />

    <x-numerosis::registration.navigation
        continue-label="Continue to Payment"
        continue-action="register"
        :disabled="!$terms"
        loading="register"
    />

    @if(app()->isLocal())
        <div class="text-center">
            <flux:button
                tag="a"
                href="{{ route('checkout.subscription.dev', [
                    'billing_cycle' => $cycle->value,
                    'name' => $this->state()->get('name'),
                    'domain' => $this->state()->get('domain'),
                    'payment_plan' => $payment_plan,
                    'global_id' => GetAuthenticatedUser::run()?->global_id,
                ]) }}"
                variant="ghost"
                size="sm"
            >
                Skip Stripe (local only)
            </flux:button>
        </div>
    @endif

    <!-- Helper Text -->
    <div class="text-center">
        <p class="text-sm text-zinc-500 dark:text-zinc-400 max-w-md mx-auto">
            Your workspace will be ready in minutes. We'll send you a confirmation email once it's set up.
        </p>
    </div>
</div>
