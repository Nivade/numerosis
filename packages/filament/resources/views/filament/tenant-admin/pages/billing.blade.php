@php
    use Nvade\Numerosis\Enums\Billing\BillingCycle;

    $subscription = $this->subscription;
@endphp

<x-filament-panels::page>
    <div class="space-y-10">
        {{-- Shorter payment-status-banner already renders panel-wide, see
             TenantAdminPanelProvider's CONTENT_START render hook. --}}
        <x-numerosis::billing.dunning-alert :subscription="$subscription" />

        {{-- Current Subscription Overview --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- Subscription Details --}}
            <div class="lg:col-span-2">
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-o-credit-card" class="size-5 text-zinc-400"/>
                            <span>Subscription Overview</span>
                        </div>
                    </x-slot>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 py-2">
                        {{-- Current Plan --}}
                        <div>
                            <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400 mb-1">
                                Current Plan
                            </p>
                            <div class="flex items-center gap-3">
                                <span class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                                    {{ $this->currentPlan?->name ?? 'No active plan' }}
                                </span>
                                @if($this->currentPlan && $subscription)
                                    <x-filament::badge color="info" variant="soft" size="sm" class="capitalize">
                                        {{ $subscription->stripe_price === $this->currentPlan->getPriceId(BillingCycle::Monthly) ? BillingCycle::Monthly->value : BillingCycle::Yearly->value }}
                                    </x-filament::badge>
                                @endif
                            </div>
                        </div>

                        {{-- Subscription Status --}}
                        <div>
                            <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400 mb-1">
                                Status
                            </p>
                            <div class="flex items-center">
                                @if ($subscription)
                                    @if ($subscription->onTrial())
                                        <x-filament::badge color="warning" icon="heroicon-m-clock">
                                            Trialing
                                        </x-filament::badge>
                                        <span class="text-sm text-zinc-500 dark:text-zinc-400 ml-3 font-medium">
                                            Ends {{ $subscription->trial_ends_at?->toFormattedDateString() }}
                                        </span>
                                    @elseif ($subscription->active())
                                        <x-filament::badge color="success" icon="heroicon-m-check-circle">
                                            Active
                                        </x-filament::badge>
                                        <span class="text-sm text-zinc-500 dark:text-zinc-400 ml-3 font-medium">
                                            Renews {{ $this->getRenewsAt() }}
                                        </span>
                                    @elseif ($subscription->canceled())
                                        <x-filament::badge color="danger" icon="heroicon-m-x-circle">
                                            Canceled
                                        </x-filament::badge>
                                        <span class="text-sm text-zinc-500 dark:text-zinc-400 ml-3 font-medium">
                                            Ends {{ $subscription->ends_at?->toFormattedDateString() }}
                                        </span>
                                    @else
                                        <x-filament::badge color="gray">
                                            {{ ucfirst($subscription->stripe_status) }}
                                        </x-filament::badge>
                                    @endif
                                @else
                                    <x-filament::badge color="gray" icon="heroicon-m-minus-circle">
                                        Not Subscribed
                                    </x-filament::badge>
                                @endif
                            </div>
                        </div>
                    </div>
                </x-filament::section>
            </div>

            {{-- Quick Actions --}}
            <div>
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-o-arrow-path" class="size-5 text-zinc-400"/>
                            <span>Quick Actions</span>
                        </div>
                    </x-slot>

                    <div class="space-y-3 py-2">
                        <x-filament::button
                            wire:click="redirectToStripePortal"
                            icon="heroicon-m-arrow-top-right-on-square"
                            color="gray"
                            variant="outline"
                            class="w-full justify-start"
                        >
                            Billing Portal
                        </x-filament::button>

                        <p class="text-xs text-zinc-500 dark:text-zinc-400 px-1 leading-relaxed">
                            Manage payment methods, download invoices, or update your billing information in our secure Stripe portal.
                        </p>
                    </div>
                </x-filament::section>
            </div>
        </div>

        {{-- Available Plans Section --}}
        <div class="space-y-8">
            {{-- Section Header --}}
            <div class="text-center space-y-4 p-4">
                <h2 class="text-3xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-4xl">
                    Available Plans
                </h2>
                <p class="text-lg text-zinc-600 dark:text-zinc-400 max-w-2xl mx-auto">
                    Choose the perfect plan for your team. All plans include a 14-day free trial.
                </p>

                {{-- Billing Cycle Toggle --}}
                <x-numerosis::billing.cycle-toggle :billing-cycle="$billingCycle" max-savings="20" class="mt-6" />
            </div>

            {{-- Plan Cards Grid --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                @foreach ($this->plans as $plan)
                    @php
                        $isCurrentPlan = $this->currentPlan && $this->currentPlan->slug === $plan->slug;
                        $priceId = $plan->getPriceId($this->billingCycle);
                        $isCurrentCycle = $isCurrentPlan && $this->subscription && $this->subscription->stripe_price === $priceId;
                        $price = $plan->getPrice($this->billingCycle);
                        $incentive = $plan->getIncentive($billingCycle);
                    @endphp

                    <x-numerosis::billing.plan-card
                        :plan="$plan"
                        :billing-cycle="$billingCycle"
                        :is-current-cycle="$isCurrentCycle"
                        :is-current-plan="$isCurrentPlan"
                        :subscription="$subscription"
                        :price="$this->formatAmount($price)"
                        :incentive="$incentive"
                    />
                @endforeach
            </div>

            {{-- Plan Change Confirmation Modals --}}
            @foreach ($this->plans as $plan)
                @php
                    $isCurrentPlan = $this->currentPlan && $this->currentPlan->slug === $plan->slug;
                    $priceId = $plan->getPriceId($this->billingCycle);
                    $isCurrentCycle = $isCurrentPlan && $this->subscription && $this->subscription->stripe_price === $priceId;
                    $price = $plan->getPrice($this->billingCycle);
                @endphp

                @if(!$isCurrentCycle)
                    <flux:modal name="confirm-plan-change-{{ $plan->slug }}-{{ $billingCycle->value }}" class="md:w-md">
                        <form wire:submit="changePlan('{{ $plan->slug }}', '{{ $billingCycle->value }}')">
                            <div class="space-y-6">
                                {{-- Modal Header --}}
                                <div>
                                    <flux:heading size="lg">Confirm Plan Change</flux:heading>
                                    <flux:subheading class="mt-2">
                                        Review the changes before confirming
                                    </flux:subheading>
                                </div>

                                {{-- Plan Change Details --}}
                                <div class="space-y-4 p-4 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg border border-zinc-200 dark:border-zinc-700">
                                    @if($this->currentPlan)
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                                    Current Plan
                                                </p>
                                                <p class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">
                                                    {{ $this->currentPlan->name }}
                                                    @if($this->subscription)
                                                        <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">
                                                            ({{ $this->subscription->stripe_price === $this->currentPlan->getPriceId(BillingCycle::Monthly) ? 'Monthly' : 'Yearly' }})
                                                        </span>
                                                    @endif
                                                </p>
                                            </div>
                                            <x-filament::icon
                                                icon="heroicon-m-arrow-right"
                                                class="size-5 text-zinc-400 dark:text-zinc-500"
                                            />
                                        </div>
                                    @endif

                                    <div>
                                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                            New Plan
                                        </p>
                                        <p class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">
                                            {{ $plan->name }}
                                            <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">
                                                ({{ $billingCycle === \Nvade\Numerosis\Enums\Billing\BillingCycle::Monthly ? 'Monthly' : 'Yearly' }})
                                            </span>
                                        </p>
                                        <p class="mt-1 text-lg font-bold text-primary">
                                            {{ $this->formatAmount($price) }}
                                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">
                                                {{ $billingCycle->label() }}
                                            </span>
                                        </p>
                                    </div>
                                </div>

                                {{-- Important Notice --}}
                                @if($this->subscription && $this->subscription->active())
                                    <x-filament::section
                                        icon="heroicon-m-information-circle"
                                        icon-color="info"
                                    >
                                        <x-slot name="heading">
                                            What happens next?
                                        </x-slot>

                                        <div class="space-y-2 text-sm text-zinc-600 dark:text-zinc-400">
                                            <p>• Your plan will be updated immediately</p>
                                            <p>• You'll be charged the prorated amount</p>
                                            <p>• Your next billing date will remain the same</p>
                                            <p>• An invoice will be generated for your records</p>
                                        </div>
                                    </x-filament::section>
                                @endif

                                {{-- Action Buttons --}}
                                <div class="flex gap-3 justify-end">
                                    <flux:modal.close>
                                        <flux:button variant="ghost" type="button">
                                            Cancel
                                        </flux:button>
                                    </flux:modal.close>

                                    <flux:button
                                        type="submit"
                                        variant="filled"
                                        color="primary"
                                    >
                                        @if($this->subscription && $this->subscription->active())
                                            Confirm & Update
                                        @else
                                            Proceed to Checkout
                                        @endif
                                    </flux:button>
                                </div>
                            </div>
                        </form>
                    </flux:modal>
                @endif
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
