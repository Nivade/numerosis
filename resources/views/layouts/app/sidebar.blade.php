<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('numerosis::partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <div class="flex min-h-screen">
            <!-- Sidebar -->
            <flux:sidebar sticky stashable class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:sidebar.toggle class="lg:hidden" icon="x-mark"/>

                <a href="{{ route('tenants.mine') }}" class="me-5 flex items-center space-x-2 rtl:space-x-reverse" wire:navigate>
                    <x-numerosis::app-logo/>
                </a>

                <flux:navlist variant="outline">
                    <flux:navlist.group :heading="__('Platform')" class="grid">
                        <flux:navlist.item icon="home" :href="route('tenants.mine')" :current="request()->routeIs('tenants.mine')"
                                           wire:navigate>{{ __('My Tenants') }}</flux:navlist.item>
                        <flux:navlist.item icon="building-office" :href="route('tenants.index')"
                                           :current="request()->routeIs('tenants.index')"
                                           wire:navigate>{{ __('Browse Tenants') }}</flux:navlist.item>

                    </flux:navlist.group>
                </flux:navlist>

                <flux:spacer/>

                <flux:navlist variant="outline">
                    <flux:navlist.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit" target="_blank">
                        {{ __('Repository') }}
                    </flux:navlist.item>

                    <flux:navlist.item icon="book-open-text" href="https://laravel.com/docs/starter-kits#livewire" target="_blank">
                        {{ __('Documentation') }}
                    </flux:navlist.item>
                </flux:navlist>

                <!-- Desktop User Menu -->
                <flux:dropdown class="hidden lg:block" position="bottom" align="start">
                    <flux:profile
                        :name="auth()->user()->name"
                        :initials="auth()->user()->initials()"
                        icon:trailing="chevrons-up-down"
                    />

                    <flux:menu class="w-[220px]">
                        <flux:menu.radio.group>
                            <div class="p-0 text-sm font-normal">
                                <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                            <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                                <span
                                                    class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                                >
                                                    {{ auth()->user()->initials() }}
                                                </span>
                                            </span>

                                    <div class="grid flex-1 text-start text-sm leading-tight">
                                        <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                        <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                    </div>
                                </div>
                            </div>
                        </flux:menu.radio.group>

                        <flux:menu.separator/>

                        <flux:menu.radio.group>
                            <flux:menu.item :href="route('settings.profile')" icon="cog"
                                            wire:navigate>{{ __('Settings') }}</flux:menu.item>
                        </flux:menu.radio.group>

                        @php
                            $tenants = auth()->user()->tenants()->with('domains')->get();
                        @endphp

                        @if($tenants->isNotEmpty())
                            <x-numerosis::ui.accordion>
                                <x-numerosis::ui.accordion.item name="tenants">
                                    <x-numerosis::ui.accordion.heading icon="building-office">
                                        {{ __('Tenants') }}
                                    </x-numerosis::ui.accordion.heading>

                                    <x-numerosis::ui.accordion.content>
                                        <div class="space-y-0.5 ps-4">
                                            @foreach ($tenants as $tenant)
                                                @php
                                                    $host = $tenant->domains->first()?->domain;
                                                    $url = $host ? (str_starts_with($host, 'http') ? $host : (request()->getScheme().'://'.$host)) : '#';
                                                @endphp
                                                <flux:menu.item :href="$url" :target="str_starts_with($url, 'http') ? '_blank' : '_self'">
                                                    {{ $tenant->name }}
                                                </flux:menu.item>
                                            @endforeach
                                        </div>
                                    </x-numerosis::ui.accordion.content>
                                </x-numerosis::ui.accordion.item>
                            </x-numerosis::ui.accordion>
                        @endif

                        <flux:menu.separator/>

                        <form method="POST" action="{{ route('logout') }}" class="w-full">
                            @csrf
                            <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                                {{ __('Log Out') }}
                            </flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
            </flux:sidebar>

            <!-- Main Content Area -->
            <div class="flex flex-1 flex-col">
                <!-- Mobile Header -->
                <flux:header class="lg:hidden">
                    <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left"/>

                    <flux:spacer/>

                    <flux:dropdown position="top" align="end">
                        <flux:profile
                            :initials="auth()->user()->initials()"
                            icon-trailing="chevron-down"
                        />

                        <flux:menu>
                            <flux:menu.radio.group>
                                <div class="p-0 text-sm font-normal">
                                    <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                                    <span
                                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                                    >
                                                        {{ auth()->user()->initials() }}
                                                    </span>
                                                </span>

                                        <div class="grid flex-1 text-start text-sm leading-tight">
                                            <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                            <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                        </div>
                                    </div>
                                </div>
                            </flux:menu.radio.group>

                            <flux:menu.separator/>

                            <flux:menu.radio.group>
                                <flux:menu.item :href="route('settings.profile')" icon="cog"
                                                wire:navigate>{{ __('Settings') }}</flux:menu.item>
                            </flux:menu.radio.group>

                            @if($tenants->isNotEmpty())
                                <x-numerosis::ui.accordion>
                                    <x-numerosis::ui.accordion.item name="tenants-mobile">
                                        <x-numerosis::ui.accordion.heading icon="building-office">
                                            {{ __('Tenants') }}
                                        </x-numerosis::ui.accordion.heading>

                                        <x-numerosis::ui.accordion.content>
                                            <div class="space-y-0.5 ps-4">
                                                @foreach ($tenants as $tenant)
                                                    @php
                                                        $host = $tenant->domains->first()?->domain;
                                                        $url = $host ? (str_starts_with($host, 'http') ? $host : (request()->getScheme().'://'.$host)) : '#';
                                                    @endphp
                                                    <flux:menu.item :href="$url" :target="str_starts_with($url, 'http') ? '_blank' : '_self'">
                                                        {{ $tenant->name }}
                                                    </flux:menu.item>
                                                @endforeach
                                            </div>
                                        </x-numerosis::ui.accordion.content>
                                    </x-numerosis::ui.accordion.item>
                                </x-numerosis::ui.accordion>
                            @endif

                            <flux:menu.separator/>

                            <form method="POST" action="{{ route('logout') }}" class="w-full">
                                @csrf
                                <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                                    {{ __('Log Out') }}
                                </flux:menu.item>
                            </form>
                        </flux:menu>
                    </flux:dropdown>
                </flux:header>

                <!-- Main Content -->
                <main class="flex-1 overflow-hidden p-6">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @fluxScripts
    </body>
</html>
