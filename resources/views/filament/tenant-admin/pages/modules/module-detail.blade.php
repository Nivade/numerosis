<x-filament::page>
    <div
        x-data="stripeConfirm(@js(config('cashier.key')), @js(__('billing.decline_codes')))"
        x-init="init()"
        class="space-y-6"
    >
        <x-numerosis::billing.payment-error :message="$paymentError" />

        @php
            $offer = $this->offer();
            $purchased = $this->purchased();
            $prices = $this->recurringPrices();
        @endphp

        <a
            href="{{ \Nvade\Numerosis\Filament\TenantAdmin\Pages\Modules\Marketplace::getUrl() }}"
            wire:navigate
            class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white"
        >
            <x-filament::icon icon="heroicon-o-arrow-left" class="size-4" />
            Back to marketplace
        </a>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
            <div class="lg:col-span-2 bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-800 rounded-2xl p-8">
                <div class="flex items-start gap-4">
                    <div @class([
                        'flex items-center justify-center size-14 rounded-2xl shrink-0',
                        'bg-green-50 dark:bg-green-900/20' => $purchased,
                        'bg-gray-50 dark:bg-zinc-800' => ! $purchased,
                    ])>
                        <x-filament::icon
                            icon="heroicon-o-puzzle-piece"
                            @class([
                                'size-7',
                                'text-green-600 dark:text-green-400' => $purchased,
                                'text-gray-500 dark:text-gray-400' => ! $purchased,
                            ])
                        />
                    </div>

                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-xl font-bold text-gray-900 dark:text-white">
                                {{ $offer->name() }}
                            </h2>

                            @if($purchased)
                                <x-filament::badge color="success" icon="heroicon-m-check-badge">
                                    Installed
                                </x-filament::badge>
                            @else
                                <x-filament::badge color="gray">
                                    {{ $offer->billingMode() === \Nvade\Numerosis\Enums\ModuleBillingMode::OneTime ? 'One-time purchase' : 'Subscription' }}
                                </x-filament::badge>
                            @endif
                        </div>
                    </div>
                </div>

                <p class="mt-6 text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                    {{ $offer->description() }}
                </p>
            </div>

            <div class="bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-800 rounded-2xl p-8">
                @if($purchased)
                    <div class="flex flex-col items-center text-center gap-3 py-2">
                        <x-filament::icon icon="heroicon-o-check-circle" class="size-10 text-green-500" />
                        <div class="font-semibold text-gray-900 dark:text-white">Already installed</div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            This module is live on your workspace.
                        </p>
                    </div>
                @else
                    @if($offer->billingMode() === \Nvade\Numerosis\Enums\ModuleBillingMode::OneTime)
                        <div class="flex items-baseline gap-1.5">
                            <span class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white">
                                {{ $this->oneTimePrice() ?? '—' }}
                            </span>
                            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">one-time</span>
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Charged once to your card on file.</p>
                    @else
                        <div class="flex items-baseline gap-1.5">
                            <span class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white">
                                {{ $prices['monthly'] ?? '—' }}
                            </span>
                            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">/mo</span>
                        </div>
                        @if($prices['yearly'])
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                or {{ $prices['yearly'] }}/yr — prorated onto your existing subscription.
                            </p>
                        @endif
                    @endif

                    @if($this->canPurchaseModules())
                        <x-filament::button
                            size="lg"
                            class="w-full mt-6"
                            wire:click="mountAction('purchase', { slug: '{{ $offer->slug() }}' })"
                            wire:loading.attr="disabled"
                            wire:target="mountAction('purchase', { slug: '{{ $offer->slug() }}' })"
                        >
                            Purchase
                        </x-filament::button>
                    @else
                        <p class="mt-6 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('billing.modules.purchase_not_authorized') }}
                        </p>
                    @endif
                @endif
            </div>
        </div>

        <x-filament-actions::modals />
    </div>
</x-filament::page>
