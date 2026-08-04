@props([
    'invitation' => null,
])

@php
    $providers = \App\Support\Features::enabled(\App\Features\Social\SocialLoginFeature::NAME)
        ? \App\Support\Social\ConfiguredProviders::all()
        : [];
@endphp

@if ($providers !== [])
    <div class="flex flex-col gap-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @foreach ($providers as $key => $meta)
                <x-auth.buttons.social :provider="$key" :invitation="$invitation" class="{{ $meta['hover'] }}">
                    <div class="flex items-center">
                        <x-dynamic-component :component="'icons.'.$key" />
                        <span>{{ $meta['label'] }}</span>
                    </div>
                </x-auth.buttons.social>
            @endforeach
        </div>
    </div>
@endif
