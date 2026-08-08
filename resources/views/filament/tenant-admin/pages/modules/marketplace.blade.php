<x-filament::page>
    <div
        x-data="stripeConfirm(@js(config('cashier.key')), @js(__('numerosis::billing.decline_codes')))"
        x-init="init()"
        class="space-y-6"
    >
        <x-numerosis::billing.payment-error :message="$paymentError" />

        @php($modules = $this->getModules())

        @if($modules->isEmpty())
            <div class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-zinc-300 dark:border-zinc-700 py-16 text-center">
                <x-filament::icon icon="heroicon-o-shopping-bag" class="size-10 text-zinc-400 dark:text-zinc-600" />
                <div class="text-base font-semibold text-zinc-900 dark:text-white">No modules available yet</div>
                <p class="max-w-sm text-sm text-zinc-500 dark:text-zinc-400">
                    Check back soon — new modules will show up here as they're released.
                </p>
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($modules as $module)
                    <div @class([
                        'relative flex flex-col h-full p-6 bg-white dark:bg-zinc-900 border rounded-xl transition-all duration-300',
                        'border-success-border' => $module['purchased'],
                        'border-zinc-200 dark:border-zinc-800 shadow-sm hover:shadow-md hover:-translate-y-0.5' => ! $module['purchased'],
                    ])>
                        <a
                            href="{{ \Nvade\Numerosis\Filament\TenantAdmin\Pages\Modules\ModuleDetail::getUrl(['slug' => $module['slug']]) }}"
                            wire:navigate
                            class="flex-1 focus-ring rounded-lg"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div @class([
                                    'flex items-center justify-center size-10 rounded-xl shrink-0',
                                    'bg-success-bg' => $module['purchased'],
                                    'bg-zinc-50 dark:bg-zinc-800' => ! $module['purchased'],
                                ])>
                                    <x-filament::icon
                                        icon="heroicon-o-puzzle-piece"
                                        @class([
                                            'size-5',
                                            'text-success-icon' => $module['purchased'],
                                            'text-zinc-500 dark:text-zinc-400' => ! $module['purchased'],
                                        ])
                                    />
                                </div>

                                @if($module['purchased'])
                                    <x-filament::badge color="success" icon="heroicon-m-check-badge">
                                        Installed
                                    </x-filament::badge>
                                @else
                                    <x-filament::badge color="gray">
                                        {{ $module['billing_mode'] === \Nvade\Numerosis\Enums\ModuleBillingMode::OneTime ? 'One-time' : 'Subscription' }}
                                    </x-filament::badge>
                                @endif
                            </div>

                            <div class="mt-4">
                                <div class="font-semibold text-base text-zinc-900 dark:text-white hover:underline">
                                    {{ $module['name'] }}
                                </div>
                                <p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-400 leading-relaxed">
                                    {{ $module['description'] }}
                                </p>
                            </div>
                        </a>

                        <div class="mt-5 flex items-center justify-between gap-3">
                            @if($module['price_label'])
                                <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                                    {{ $module['price_label'] }}
                                </span>
                            @else
                                <span></span>
                            @endif

                            @if($module['purchased'])
                                <x-filament::icon icon="heroicon-o-check-circle" class="size-6 text-success-icon" />
                            @elseif($this->canPurchaseModules())
                                <x-filament::button
                                    size="sm"
                                    wire:click="mountAction('purchase', { slug: '{{ $module['slug'] }}' })"
                                >
                                    Purchase
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <x-filament-actions::modals />
    </div>
</x-filament::page>
