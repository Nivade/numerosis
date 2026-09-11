<x-numerosis-layouts::app :title="config('app.name')">
    {{--
        The package's placeholder homepage.

        `home` is the one route name core guarantees exists on the central
        domain — OAuth tenant redirects, checkout error paths and the tenant
        panel all fall back to it, so it cannot sit behind a feature flag.
        Core therefore has to render *something* here, but it must not be a
        marketing site: those pages belong to the product, and live in the
        host app.

        Replace this by registering your own `home` route from the host, or by
        publishing this view.
    --}}
    <div class="mx-auto flex max-w-2xl flex-col items-center justify-center gap-6 px-6 py-24 text-center">
        <x-numerosis::app-logo/>

        <flux:heading level="1" size="xl">{{ config('app.name') }}</flux:heading>

        <flux:text>{{ __('This application is running on Numerosis.') }}</flux:text>

        <div class="flex flex-wrap justify-center gap-3">
            @auth
                @if (Route::has(\Nvade\Numerosis\Routing\RouteNames::tenantsMine()))
                    <flux:button :href="route(\Nvade\Numerosis\Routing\RouteNames::tenantsMine())" wire:navigate variant="primary">
                        {{ __('Your workspaces') }}
                    </flux:button>
                @endif
            @else
                @if (Route::has('login'))
                    <flux:button :href="route('login')" wire:navigate variant="primary">
                        {{ __('Log in') }}
                    </flux:button>
                @endif

                @if (\Nvade\Numerosis\Features\Tenancy\RegistrationWizardFeature::available())
                    <flux:button :href="route('tenants.create')" wire:navigate>
                        {{ __('Get started') }}
                    </flux:button>
                @endif
            @endauth
        </div>
    </div>
</x-numerosis-layouts::app>
