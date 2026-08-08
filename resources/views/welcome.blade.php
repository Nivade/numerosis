<x-layouts::app :title="__('Welcome')">
    <section class="relative overflow-hidden">
        <x-numerosis::ui.hero-gradient />

        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-16 pb-12 sm:pt-24 sm:pb-16">
            <div class="grid gap-10 lg:grid-cols-2 lg:items-center">
                <div class="space-y-6">
                    <flux:badge>Team Communication</flux:badge>

                    <flux:heading level="1" class="text-4xl sm:text-5xl font-extrabold tracking-tight">
                        Talk less. Align more.
                    </flux:heading>

                    <p class="text-lg text-zinc-600 dark:text-zinc-300 max-w-xl">
                        A flexible team workspace where you collaborate in channels, share files, and keep work moving. Create your own workspace in seconds and start shipping.
                    </p>

                    <div class="flex flex-wrap items-center gap-3">
                        @auth
                            @if (\Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Ui\AccountPagesFeature::NAME))
                                <flux:button as="a" variant="primary" href="{{ route('tenants.mine') }}" wire:navigate icon="home">
                                    Go to Dashboard
                                </flux:button>
                            @endif
                        @else
                            @if (\Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Tenancy\RegistrationWizardFeature::NAME))
                                <flux:button as="a" variant="primary" href="{{ route('tenants.create') }}" wire:navigate icon="rocket-launch">
                                    Get started — it's free
                                </flux:button>
                            @endif
                            <flux:button as="a" variant="ghost" href="{{ route('login') }}" wire:navigate icon="arrow-right-start-on-rectangle">
                                Sign in
                            </flux:button>
                        @endauth
                    </div>

                    <div class="flex items-center gap-4 pt-2 text-sm text-zinc-500 dark:text-zinc-400">
                        <div class="flex items-center gap-1">
                            <flux:icon name="shield-check" class="text-success-icon" variant="micro" />
                            SSO-ready
                        </div>
                        <div class="flex items-center gap-1">
                            <flux:icon name="lock-closed" class="text-success-icon" variant="micro" />
                            Your data, isolated per workspace
                        </div>
                    </div>
                </div>

                <div class="relative">
                    <div class="aspect-[16/10] rounded-2xl ring-1 ring-zinc-200/80 dark:ring-white/10 bg-linear-to-br from-zinc-50 to-zinc-100 dark:from-zinc-800 dark:to-zinc-900 p-4">
                        <div class="h-full w-full rounded-xl bg-white/70 dark:bg-zinc-900/60 backdrop-blur grid grid-rows-[auto_1fr]">
                            <div class="flex items-center gap-2 border-b border-zinc-200/70 dark:border-white/10 px-3 py-2">
                                <div class="flex gap-1">
                                    <span class="size-2.5 rounded-full bg-red-400"></span>
                                    <span class="size-2.5 rounded-full bg-amber-400"></span>
                                    <span class="size-2.5 rounded-full bg-emerald-400"></span>
                                </div>
                                <span class="ms-2 text-xs text-zinc-500 dark:text-zinc-400"># product-team</span>
                            </div>
                            <div class="p-4 overflow-hidden">
                                <div class="space-y-3 text-sm">
                                    <div class="flex items-start gap-3">
                                        <flux:avatar name="Alex" />
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium">Alex</span>
                                                <span class="text-zinc-400 text-xs">10:24 AM</span>
                                            </div>
                                            <p class="text-zinc-600 dark:text-zinc-300">Drafted the launch plan. Need feedback by EOD.</p>
                                        </div>
                                    </div>
                                    <div class="flex items-start gap-3">
                                        <flux:avatar name="Sam" />
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium">Sam</span>
                                                <span class="text-zinc-400 text-xs">10:27 AM</span>
                                            </div>
                                            <p class="text-zinc-600 dark:text-zinc-300">Looks great! I’ll take the pricing section.</p>
                                        </div>
                                    </div>
                                    <div class="flex items-start gap-3">
                                        <flux:avatar name="Riley" />
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium">Riley</span>
                                                <span class="text-zinc-400 text-xs">10:29 AM</span>
                                            </div>
                                            <p class="text-zinc-600 dark:text-zinc-300">Shipping an update to channels now.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-12 sm:py-20">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid gap-8 sm:gap-10 md:grid-cols-2 lg:grid-cols-3">
                <x-numerosis::ui.card>
                    <div class="flex items-center gap-2 mb-3">
                        <flux:icon name="hashtag" />
                        <span class="font-semibold">Channels</span>
                    </div>
                    <p class="text-zinc-600 dark:text-zinc-300">Organize conversations by topic, project, or team with real-time updates.</p>
                </x-numerosis::ui.card>

                <x-numerosis::ui.card>
                    <div class="flex items-center gap-2 mb-3">
                        <flux:icon name="paper-clip" />
                        <span class="font-semibold">Files</span>
                    </div>
                    <p class="text-zinc-600 dark:text-zinc-300">Share and find files fast with previews and searchable history.</p>
                </x-numerosis::ui.card>

                <x-numerosis::ui.card>
                    <div class="flex items-center gap-2 mb-3">
                        <flux:icon name="bolt" />
                        <span class="font-semibold">Workflows</span>
                    </div>
                    <p class="text-zinc-600 dark:text-zinc-300">Automate routine tasks so your team can focus on important work.</p>
                </x-numerosis::ui.card>

                <x-numerosis::ui.card>
                    <div class="flex items-center gap-2 mb-3">
                        <flux:icon name="shield-check" />
                        <span class="font-semibold">Security</span>
                    </div>
                    <p class="text-zinc-600 dark:text-zinc-300">Enterprise-grade security with per-tenant data isolation.</p>
                </x-numerosis::ui.card>

                <x-numerosis::ui.card>
                    <div class="flex items-center gap-2 mb-3">
                        <flux:icon name="magnifying-glass" />
                        <span class="font-semibold">Search</span>
                    </div>
                    <p class="text-zinc-600 dark:text-zinc-300">Everything is searchable — conversations, files, links, and more.</p>
                </x-numerosis::ui.card>

                <x-numerosis::ui.card>
                    <div class="flex items-center gap-2 mb-3">
                        <flux:icon name="sparkles" />
                        <span class="font-semibold">Delightful</span>
                    </div>
                    <p class="text-zinc-600 dark:text-zinc-300">Crafted with Flux UI and Tailwind to feel fast and familiar.</p>
                </x-numerosis::ui.card>
            </div>

            @if (\Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Tenancy\RegistrationWizardFeature::NAME))
                <div class="mt-12 sm:mt-16 text-center">
                    <flux:button as="a" variant="primary" href="{{ route('tenants.create') }}" wire:navigate icon="rocket-launch">
                        Create your workspace
                    </flux:button>
                </div>
            @endif
        </div>
    </section>
</x-layouts::app>
