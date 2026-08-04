@props([
    'plan',
    'billingCycle',
    'isCurrentCycle' => false,
    'isCurrentPlan' => false,
    'subscription' => null,
    'price',
    'incentive' => null,
    'type' => 'display', // 'display' (for billing page) or 'selectable' (for registration wizard)
])

@php
    /** @var \App\Models\Central\PaymentPlan $plan */
    /** @var \App\Enums\BillingCycle $billingCycle */
    $isPopular = $plan->is_popular;

    // `$price` arrives already formatted ("€ 10,00"), so it cannot be compared
    // against 0: PHP compares a non-numeric string to an int as strings, and
    // every currency symbol sorts above "0" — which made "€ 0,00" > 0 true and
    // left the free branch below unreachable. Ask the plan for the number.
    $isFree = ! ($plan->getPrice($billingCycle) > 0);
@endphp

@if($type === 'selectable')
    {{-- Selectable Plan Card (for registration wizard) --}}
    <label class="relative group cursor-pointer w-full focus-within:ring-2 focus-within:ring-blue-500 focus-within:ring-offset-2 rounded-2xl">
        <input
            type="radio"
            wire:model="payment_plan"
            value="{{ $plan->slug }}"
            class="sr-only peer"
        >

        <div @class([
            'flex flex-col h-full p-6 border-2 transition-all duration-300 transform hover:-translate-y-1 hover:shadow-xl relative overflow-hidden rounded-2xl',
            'border-gray-200 dark:border-zinc-700 peer-checked:border-blue-500 peer-checked:bg-blue-50/50 dark:peer-checked:bg-blue-900/10 hover:border-blue-300 dark:hover:border-blue-700',
        ])>
            {{-- Popular Badge --}}
            @if($isPopular)
                <div class="absolute top-0 right-0">
                    <div class="bg-blue-500 text-white text-[10px] font-bold px-3 py-1 rounded-bl-lg uppercase tracking-wider">
                        Most Popular
                    </div>
                </div>
            @endif

            <div class="flex flex-col h-full w-full">
                {{-- Plan Header --}}
                <div class="flex items-center justify-between mb-6">
                    <div class="flex-1">
                        {{-- Plan Name & Incentive --}}
                        <div class="flex items-center gap-2">
                            <h3 class="text-xl font-bold text-gray-900 dark:text-white leading-none">
                                {{ $plan->name }}
                            </h3>
                            @if($incentive)
                                <flux:badge variant="solid" color="green" size="sm" class="uppercase text-[9px] font-bold px-1.5 py-0.5">
                                    {{ $incentive }}
                                </flux:badge>
                            @endif
                        </div>

                        {{-- Price --}}
                        <div class="mt-4 flex items-baseline">
                            @unless($isFree)
                                <span class="text-4xl font-black text-gray-900 dark:text-white tracking-tight">
                                    {{ $price }}
                                </span>
                                <span class="ml-1.5 text-sm font-medium text-gray-500 dark:text-gray-400">
                                    {{ $billingCycle->label() }}
                                </span>
                            @else
                                <span class="text-4xl font-black text-gray-900 dark:text-white tracking-tight">
                                    Free
                                </span>
                            @endif
                        </div>

                        {{-- Savings Message --}}
                        @if($billingCycle === \App\Enums\BillingCycle::Yearly && $plan->getSavingsPercentage() > 0)
                            <p class="text-[11px] font-semibold text-green-600 dark:text-green-400 mt-1 uppercase tracking-wider">
                                Save {{ $plan->getSavingsPercentage() }}% with annual billing
                            </p>
                        @endif
                    </div>

                    {{-- Selected Checkmark --}}
                    <div class="peer-checked:block hidden shrink-0">
                        <div class="bg-blue-500 rounded-full p-1 shadow-sm">
                            <flux:icon.check variant="micro" class="size-4 text-white"/>
                        </div>
                    </div>
                </div>

                {{-- Plan Description --}}
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-6 min-h-10 leading-relaxed">
                    {{ $plan->description }}
                </p>

                {{-- Features List --}}
                <div class="space-y-4 grow">
                    <h4 class="font-bold text-[11px] text-gray-400 dark:text-gray-500 uppercase tracking-widest">
                        What's included:
                    </h4>

                    <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-3">
                        @foreach($plan->availableFeatures()->take(6)->get() as $feature)
                            <x-feature-line :feature="$feature"/>
                        @endforeach
                    </ul>

                    {{-- All Features Modal --}}
                    @if($plan->availableFeatures()->count() > 6)
                        <div class="mt-3">
                            <flux:modal.trigger name="features-{{ $plan->slug }}">
                                <flux:button variant="subtle" size="sm" class="-ml-2">
                                    See all features
                                </flux:button>
                            </flux:modal.trigger>

                            <flux:modal name="features-{{ $plan->slug }}" class="md:w-lg">
                                <div class="space-y-6">
                                    <flux:heading size="lg">{{ $plan->name }} Features</flux:heading>

                                    <ul class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4">
                                        @foreach($plan->features()->get() as $feature)
                                            <x-feature-line
                                                :feature="$feature"
                                                :available="$feature->pivot->available"
                                            />
                                        @endforeach
                                    </ul>

                                    <div class="flex">
                                        <flux:spacer/>
                                        <flux:modal.close>
                                            <flux:button variant="ghost">Close</flux:button>
                                        </flux:modal.close>
                                    </div>
                                </div>
                            </flux:modal>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </label>
@else
    {{-- Display Plan Card (for billing page) --}}
    <div @class([
        'relative flex flex-col h-full p-8 bg-white dark:bg-zinc-900 border transition-all duration-300 rounded-3xl',
        'ring-2 ring-blue-500 border-transparent shadow-xl lg:scale-105 z-20' => $isPopular && !$isCurrentCycle,
        'ring-2 ring-green-500 border-transparent shadow-xl lg:scale-105 z-20' => $isCurrentCycle,
        'border-gray-200 dark:border-zinc-800 shadow-sm hover:shadow-md' => !$isPopular && !$isCurrentCycle,
    ])>
        {{-- Active/Popular Badge --}}
        @if($isCurrentCycle)
            <div class="absolute -top-4 left-1/2 -translate-x-1/2 px-4 py-1 bg-green-500 text-white text-xs font-bold rounded-full tracking-wider uppercase shadow-md flex items-center gap-1.5">
                <x-filament::icon icon="heroicon-m-check-badge" class="size-3.5"/>
                Your Active Plan
            </div>
        @elseif($isPopular)
            <div class="absolute -top-4 left-1/2 -translate-x-1/2 px-4 py-1 bg-blue-500 text-white text-xs font-bold rounded-full tracking-wider uppercase shadow-md">
                Most Popular
            </div>
        @endif

        {{-- Plan Info --}}
        <div class="mb-8">
            <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                {{ $plan->name }}
            </h3>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                {{ $plan->description }}
            </p>
        </div>

        {{-- Pricing --}}
        <div class="mb-8">
            <div class="flex items-baseline gap-1.5">
                <span class="text-4xl font-bold tracking-tight text-gray-900 dark:text-white">
                    {{ $isFree ? 'Free' : $price }}
                </span>
                @unless($isFree)
                    <span class="text-sm font-semibold text-gray-500 dark:text-gray-400">
                        {{ $billingCycle->label() }}
                    </span>
                @endunless
            </div>

            @if($incentive)
                <p class="mt-2 text-xs font-medium text-green-600 dark:text-green-400">
                    {{ $incentive }}
                </p>
            @endif
        </div>

        {{-- Features List --}}
        <div class="flex-1 space-y-4 mb-8">
            @foreach ($plan->availableFeatures as $feature)
                <x-feature-line :feature="$feature" />
            @endforeach
        </div>

        {{-- Action Button --}}
        @if ($isCurrentCycle)
            <flux:button
                disabled
                color="success"
                icon="check-circle"
                class="w-full rounded-xl py-3 shadow-sm"
            >
                Current Plan
            </flux:button>
        @else
            <flux:modal.trigger name="confirm-plan-change-{{ $plan->slug }}-{{ $billingCycle->value }}">
                <flux:button
                    wire:loading.attr="disabled"
                    @class([
                        'w-full rounded-xl py-3 shadow-sm transition-all duration-300',
                        'ring-2 ring-blue-500 ring-offset-2' => $isPopular,
                    ])
                    color="{{ $isPopular ? 'primary' : 'gray' }}"
                    variant="{{ $isPopular ? 'filled' : 'outline' }}"
                >
                    @if($isCurrentPlan)
                        Switch to {{ $billingCycle === \App\Enums\BillingCycle::Monthly ? 'Monthly' : 'Yearly' }}
                    @else
                        {{ $subscription ? 'Change Plan' : 'Get Started' }}
                    @endif
                </flux:button>
            </flux:modal.trigger>
        @endif
    </div>
@endif
