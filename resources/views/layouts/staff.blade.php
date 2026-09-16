@use(Nvade\Numerosis\Routing\RouteNames)
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    @include('numerosis::partials.head')
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        @include('numerosis::partials.toasts')

        <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" class="me-6">{{ __('numerosis::staff.title') }}</flux:heading>

            <flux:navbar class="-mb-px">
                <flux:navbar.item icon="building-office-2" :href="route('staff.tenants')"
                                  :current="request()->routeIs('staff.tenants*')" wire:navigate>
                    {{ __('numerosis::staff.nav.tenants') }}
                </flux:navbar.item>

                <flux:navbar.item icon="wrench-screwdriver" :href="route('staff.provisions')"
                                  :current="request()->routeIs('staff.provisions*')" wire:navigate>
                    {{ __('numerosis::staff.nav.provisions') }}
                </flux:navbar.item>

                <flux:navbar.item icon="queue-list" :href="route('staff.queue')"
                                  :current="request()->routeIs('staff.queue')" wire:navigate>
                    {{ __('numerosis::staff.nav.queue') }}
                </flux:navbar.item>

                <flux:navbar.item icon="credit-card" :href="route('staff.subscriptions')"
                                  :current="request()->routeIs('staff.subscriptions')" wire:navigate>
                    {{ __('numerosis::staff.nav.subscriptions') }}
                </flux:navbar.item>

                <flux:navbar.item icon="users" :href="route('staff.users')"
                                  :current="request()->routeIs('staff.users')" wire:navigate>
                    {{ __('numerosis::staff.nav.users') }}
                </flux:navbar.item>
            </flux:navbar>

            <flux:spacer/>

            <flux:navbar>
                <flux:navbar.item icon="arrow-uturn-left" :href="route(RouteNames::tenantsMine())">
                    {{ __('numerosis::staff.nav.leave') }}
                </flux:navbar.item>
            </flux:navbar>
        </flux:header>

        <flux:main container>
            {{ $slot }}
        </flux:main>

        @fluxScripts
        @livewireScripts
    </body>
</html>
