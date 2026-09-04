@php
    $providers = \Nvade\Numerosis\Enums\Auth\SocialProvider::configured();
@endphp

@if ($providers !== [])
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        @foreach ($providers as $provider)
            <flux:button
                as="a"
                :href="route(config('numerosis.social.routes.redirect.name'), $provider->value)"
                class="w-full flex items-center justify-center gap-2"
            >
                <flux:icon :icon="$provider->icon()" variant="outline" class="size-4" />
                <span>{{ $provider->label() }}</span>
            </flux:button>
        @endforeach
    </div>
@endif
