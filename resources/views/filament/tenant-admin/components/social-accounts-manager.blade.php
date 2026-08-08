<div class="space-y-4" wire:key="social-accounts-manager">
    @php
        $providers = \Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Social\SocialLoginFeature::NAME)
            ? \Nvade\Numerosis\Support\Social\ConfiguredProviders::all()
            : [];

        /** @var \Nvade\Numerosis\Models\Central\CentralUser $centralUser */
        $centralUser = Auth::guard('web')->user();
        // Force fresh query to get updated social accounts
        $connectedProviders = $centralUser?->socialiteLogins()->pluck('provider')->toArray() ?? [];
    @endphp

    @if ($providers !== [])
        @foreach ($providers as $id => $meta)
            <div class="flex items-center justify-between py-3 border-b last:border-0 border-zinc-100 dark:border-zinc-800">
                <div class="flex items-center gap-x-3">
                    <x-filament::icon
                        :icon="$meta['icon']"
                        class="h-5 w-5 text-zinc-400"
                    />
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">
                        {{ $meta['label'] }}
                    </span>
                </div>

                @if (in_array($id, $connectedProviders))
                    <div class="flex items-center gap-x-3">
                        <span class="text-xs text-success-600 dark:text-success-400 font-medium">Connected</span>
                        <flux:button
                            wire:click="mountAction('disconnectSocialAccount', { provider: '{{ $id }}' })"
                            variant="subtle"
                            size="sm"
                            color="danger"
                        >
                            Disconnect
                        </flux:button>
                    </div>
                @else
                    <flux:button
                        href="{{ route('oauth', ['driver' => $id, 'return_url' => $currentUrl]) }}"
                        variant="subtle"
                        size="sm"
                    >
                        Connect
                    </flux:button>
                @endif
            </div>
        @endforeach
    @endif

    @foreach ($connectedProviders as $id)
        @if (! isset($providers[$id]))
            <div class="flex items-center justify-between py-3 border-b last:border-0 border-zinc-100 dark:border-zinc-800">
                <div class="flex items-center gap-x-3">
                    <x-filament::icon
                        icon="heroicon-o-link"
                        class="h-5 w-5 text-zinc-400"
                    />
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">
                        {{ ucfirst($id) }}
                    </span>
                </div>

                <div class="flex items-center gap-x-3">
                    <span class="text-xs text-success-600 dark:text-success-400 font-medium">Connected</span>
                    <flux:button
                        wire:click="mountAction('disconnectSocialAccount', { provider: '{{ $id }}' })"
                        variant="subtle"
                        size="sm"
                        color="danger"
                    >
                        Disconnect
                    </flux:button>
                </div>
            </div>
        @endif
    @endforeach
</div>
