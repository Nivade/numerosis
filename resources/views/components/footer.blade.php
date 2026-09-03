<flux:footer container {{ $attributes->class('border-t border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900') }}>
    <div class="px-4 sm:px-6 lg:px-8 py-10">
        <div class="grid gap-8 md:grid-cols-3">
            <div class="space-y-3">
                <a href="{{ url('/') }}" aria-label="{{ config('app.name') }} home" class="flex items-center gap-2 rounded-md p-1 hover:opacity-90 focus-ring">
                    <x-numerosis::app-logo />
                    <span class="font-semibold">{{ config('app.name') }}</span>
                </a>
                <p class="text-sm text-zinc-600 dark:text-zinc-300 max-w-sm">
                    A flexible workspace where teams collaborate, share files, and keep work moving.
                </p>
            </div>

            <div class="grid grid-cols-2 gap-8 md:col-span-2 md:grid-cols-3">
                <div>
                    <h3 class="text-sm font-semibold mb-3">Product</h3>
                    <ul class="space-y-2 text-sm text-zinc-600 dark:text-zinc-300">
                        {{-- Marketing pages belong to the host app, so link to
                             one only when the host actually registered it. --}}
                        @if (Route::has('features'))
                            <li>
                                <a class="hover:text-zinc-900 dark:hover:text-white rounded-sm focus-ring" href="{{ route('features') }}" wire:navigate aria-label="Features">
                                    Features
                                </a>
                            </li>
                        @endif
                        @if (\Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Tenancy\RegistrationWizardFeature::NAME))
                            <li>
                                <a class="hover:text-zinc-900 dark:hover:text-white rounded-sm focus-ring" href="{{ route('tenants.create') }}" wire:navigate aria-label="Create workspace">
                                    Create workspace
                                </a>
                            </li>
                        @endif
                        @if (Route::has('login'))
                            <li>
                                <a class="hover:text-zinc-900 dark:hover:text-white rounded-sm focus-ring" href="{{ route('login') }}" wire:navigate aria-label="Sign in">
                                    Sign in
                                </a>
                            </li>
                        @endif
                        @auth
                            <li>
                                <a class="hover:text-zinc-900 dark:hover:text-white rounded-sm focus-ring" href="{{ route(\Nvade\Numerosis\Support\Routes\RouteNames::tenantsMine()) }}" wire:navigate aria-label="My Tenants">
                                    {{ __('My Tenants') }}
                                </a>
                            </li>
                        @endauth
                    </ul>
                </div>

                <div>
                    <h3 class="text-sm font-semibold mb-3">Resources</h3>
                    <ul class="space-y-2 text-sm text-zinc-600 dark:text-zinc-300">
                        <li>
                            <a class="hover:text-zinc-900 dark:hover:text-white rounded-sm focus-ring" href="https://laravel.com/docs/starter-kits#livewire" target="_blank" rel="noreferrer" aria-label="Documentation (opens in a new tab)" title="Opens in a new tab">
                                Documentation
                            </a>
                        </li>
                        <li>
                            <a class="hover:text-zinc-900 dark:hover:text-white rounded-sm focus-ring" href="https://github.com/laravel/livewire-starter-kit" target="_blank" rel="noreferrer" aria-label="Repository (opens in a new tab)" title="Opens in a new tab">
                                Repository
                            </a>
                        </li>
                    </ul>
                </div>

                @if (Route::has('about') || Route::has('privacy') || Route::has('terms'))
                    <div>
                        <h3 class="text-sm font-semibold mb-3">Company</h3>
                        <ul class="space-y-2 text-sm text-zinc-600 dark:text-zinc-300">
                            @if (Route::has('about'))
                                <li>
                                    <a class="hover:text-zinc-900 dark:hover:text-white rounded-sm focus-ring" href="{{ route('about') }}" wire:navigate aria-label="About us">About</a>
                                </li>
                            @endif
                            @if (Route::has('privacy'))
                                <li>
                                    <a class="hover:text-zinc-900 dark:hover:text-white rounded-sm focus-ring" href="{{ route('privacy') }}" wire:navigate aria-label="Privacy policy">Privacy</a>
                                </li>
                            @endif
                            @if (Route::has('terms'))
                                <li>
                                    <a class="hover:text-zinc-900 dark:hover:text-white rounded-sm focus-ring" href="{{ route('terms') }}" wire:navigate aria-label="Terms of service">Terms</a>
                                </li>
                            @endif
                        </ul>
                    </div>
                @endif
            </div>
        </div>

        <div class="mt-10 flex flex-col-reverse items-center justify-between gap-4 border-t border-zinc-200 pt-6 dark:border-zinc-700 sm:flex-row">
            <p class="text-xs text-zinc-500 dark:text-zinc-400">&copy; {{ now()->year }} {{ config('app.name') }}. All rights reserved.</p>
            <div class="flex items-center gap-3 text-xs text-zinc-500 dark:text-zinc-400 select-none" aria-label="Theme status: dark mode ready">
                <flux:icon name="sun" class="inline dark:hidden" aria-hidden="true" />
                <flux:icon name="moon" class="hidden dark:inline" aria-hidden="true" />
                <span>Dark mode ready</span>
            </div>
        </div>
    </div>
</flux:footer>
