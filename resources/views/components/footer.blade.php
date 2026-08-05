<flux:footer container {{ $attributes->class('border-t border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900') }}>
    <div class="px-4 sm:px-6 lg:px-8 py-10">
        <div class="grid gap-8 md:grid-cols-3">
            <div class="space-y-3">
                <a href="{{ url('/') }}" aria-label="{{ config('app.name') }} home" class="flex items-center gap-2 rounded-md p-1 hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 dark:focus-visible:ring-zinc-500">
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
                        @if (\Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Ui\MarketingPagesFeature::NAME))
                            <li>
                                <a class="hover:text-zinc-900 dark:hover:text-white rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 dark:focus-visible:ring-zinc-500" href="{{ route('features') }}" wire:navigate aria-label="Features">
                                    Features
                                </a>
                            </li>
                        @endif
                        @if (\Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Tenancy\RegistrationWizardFeature::NAME))
                            <li>
                                <a class="hover:text-zinc-900 dark:hover:text-white rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 dark:focus-visible:ring-zinc-500" href="{{ route('tenants.create') }}" wire:navigate aria-label="Create workspace">
                                    Create workspace
                                </a>
                            </li>
                        @endif
                        <li>
                            <a class="hover:text-zinc-900 dark:hover:text-white rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 dark:focus-visible:ring-zinc-500" href="{{ route('login') }}" wire:navigate aria-label="Sign in">
                                Sign in
                            </a>
                        </li>
                        @auth
                            @if (\Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Ui\AccountPagesFeature::NAME))
                                <li>
                                    <a class="hover:text-zinc-900 dark:hover:text-white rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 dark:focus-visible:ring-zinc-500" href="{{ route('tenants.mine') }}" wire:navigate aria-label="My Tenants">
                                        {{ __('My Tenants') }}
                                    </a>
                                </li>
                            @endif
                        @endauth
                    </ul>
                </div>

                <div>
                    <h3 class="text-sm font-semibold mb-3">Resources</h3>
                    <ul class="space-y-2 text-sm text-zinc-600 dark:text-zinc-300">
                        <li>
                            <a class="hover:text-zinc-900 dark:hover:text-white rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 dark:focus-visible:ring-zinc-500" href="https://laravel.com/docs/starter-kits#livewire" target="_blank" rel="noreferrer" aria-label="Documentation (opens in a new tab)" title="Opens in a new tab">
                                Documentation
                            </a>
                        </li>
                        <li>
                            <a class="hover:text-zinc-900 dark:hover:text-white rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 dark:focus-visible:ring-zinc-500" href="https://github.com/laravel/livewire-starter-kit" target="_blank" rel="noreferrer" aria-label="Repository (opens in a new tab)" title="Opens in a new tab">
                                Repository
                            </a>
                        </li>
                    </ul>
                </div>

                @if (\Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Ui\MarketingPagesFeature::NAME))
                    <div>
                        <h3 class="text-sm font-semibold mb-3">Company</h3>
                        <ul class="space-y-2 text-sm text-zinc-600 dark:text-zinc-300">
                            <li>
                                <a class="hover:text-zinc-900 dark:hover:text-white rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 dark:focus-visible:ring-zinc-500" href="{{ route('about') }}" wire:navigate aria-label="About us">About</a>
                            </li>
                            <li>
                                <a class="hover:text-zinc-900 dark:hover:text-white rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 dark:focus-visible:ring-zinc-500" href="{{ route('privacy') }}" wire:navigate aria-label="Privacy policy">Privacy</a>
                            </li>
                            <li>
                                <a class="hover:text-zinc-900 dark:hover:text-white rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 dark:focus-visible:ring-zinc-500" href="{{ route('terms') }}" wire:navigate aria-label="Terms of service">Terms</a>
                            </li>
                        </ul>
                    </div>
                @endif
            </div>
        </div>

        <div class="mt-10 flex flex-col-reverse items-center justify-between gap-4 border-t border-zinc-200 pt-6 dark:border-zinc-700 sm:flex-row">
            <p class="text-xs text-zinc-500 dark:text-zinc-400">&copy; {{ now()->year }} {{ config('app.name') }}. All rights reserved.</p>
            <div class="flex items-center gap-3 text-xs text-zinc-500 dark:text-zinc-400 select-none" aria-label="Theme status: dark mode ready">
                <flux:icon name="sun" class="hidden dark:inline" aria-hidden="true" />
                <flux:icon name="moon" class="inline dark:hidden" aria-hidden="true" />
                <span>Dark mode ready</span>
            </div>
        </div>
    </div>
</flux:footer>
