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
                <x-numerosis::footer.column heading="Product">
                    {{-- Marketing pages belong to the host app, so link to
                         one only when the host actually registered it. --}}
                    @if (Route::has('features'))
                        <x-numerosis::footer.link :href="route('features')">Features</x-numerosis::footer.link>
                    @endif
                    @if (\Nvade\Numerosis\Features\Tenancy\RegistrationWizardFeature::available())
                        <x-numerosis::footer.link :href="route('tenants.create')">Create workspace</x-numerosis::footer.link>
                    @endif
                    @if (Route::has('login'))
                        <x-numerosis::footer.link :href="route('login')">Sign in</x-numerosis::footer.link>
                    @endif
                    @auth
                        <x-numerosis::footer.link :href="route(\Nvade\Numerosis\Routing\RouteNames::tenantsMine())">
                            {{ __('My Tenants') }}
                        </x-numerosis::footer.link>
                    @endauth
                </x-numerosis::footer.column>

                <x-numerosis::footer.column heading="Resources">
                    <x-numerosis::footer.link external href="https://laravel.com/docs/starter-kits#livewire">Documentation</x-numerosis::footer.link>
                    <x-numerosis::footer.link external href="https://github.com/laravel/livewire-starter-kit">Repository</x-numerosis::footer.link>
                </x-numerosis::footer.column>

                @if (Route::has('about') || Route::has('privacy') || Route::has('terms'))
                    <x-numerosis::footer.column heading="Company">
                        @if (Route::has('about'))
                            <x-numerosis::footer.link :href="route('about')">About</x-numerosis::footer.link>
                        @endif
                        @if (Route::has('privacy'))
                            <x-numerosis::footer.link :href="route('privacy')">Privacy</x-numerosis::footer.link>
                        @endif
                        @if (Route::has('terms'))
                            <x-numerosis::footer.link :href="route('terms')">Terms</x-numerosis::footer.link>
                        @endif
                    </x-numerosis::footer.column>
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
