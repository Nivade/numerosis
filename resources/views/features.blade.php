<x-layouts::app :title="__('Features')">
    <section class="relative overflow-hidden">
        <x-numerosis::ui.hero-gradient />

        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-16 pb-12 sm:pt-24 sm:pb-16 text-center">
            <flux:badge>Powerful Features</flux:badge>
            <flux:heading level="1" class="mt-4 text-4xl sm:text-6xl font-extrabold tracking-tight">
                Everything you need to <br class="hidden sm:block"> stay productive.
            </flux:heading>
            <p class="mt-6 text-lg text-zinc-600 dark:text-zinc-300 max-w-2xl mx-auto">
                Discover the tools that make {{ config('app.name') }} the ultimate workspace for modern teams.
            </p>
        </div>
    </section>

    <section class="py-12 sm:py-20">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-24">
            <!-- Feature 1 -->
            <div class="grid gap-12 lg:grid-cols-2 lg:items-center">
                <div class="space-y-6">
                    <x-numerosis::ui.icon-tile icon="hashtag" color="blue" />
                    <flux:heading level="2" class="text-3xl font-bold">Channels for every topic</flux:heading>
                    <p class="text-lg text-zinc-600 dark:text-zinc-300">
                        Stay organized with dedicated channels for projects, teams, or even lunch plans. Keep everyone on the same page with real-time messaging and threaded conversations.
                    </p>
                    <ul class="space-y-3">
                        <li class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300">
                            <flux:icon name="check-circle" variant="mini" class="text-success-icon" />
                            Public and private channels
                        </li>
                        <li class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300">
                            <flux:icon name="check-circle" variant="mini" class="text-success-icon" />
                            Rich text formatting
                        </li>
                        <li class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300">
                            <flux:icon name="check-circle" variant="mini" class="text-success-icon" />
                            Mention team members
                        </li>
                    </ul>
                </div>
                <div class="rounded-2xl border border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-zinc-900 aspect-video flex items-center justify-center overflow-hidden">
                    <div class="p-8 w-full max-w-md space-y-4">
                        <div class="h-8 w-3/4 bg-zinc-200 dark:bg-zinc-800 rounded animate-pulse"></div>
                        <div class="h-24 w-full bg-zinc-200 dark:bg-zinc-800 rounded animate-pulse"></div>
                        <div class="h-8 w-1/2 bg-zinc-200 dark:bg-zinc-800 rounded animate-pulse"></div>
                    </div>
                </div>
            </div>

            <!-- Feature 2 -->
            <div class="grid gap-12 lg:grid-cols-2 lg:items-center lg:direction-rtl">
                <div class="lg:order-last space-y-6">
                    <x-numerosis::ui.icon-tile icon="paper-clip" color="emerald" />
                    <flux:heading level="2" class="text-3xl font-bold">Seamless file sharing</flux:heading>
                    <p class="text-lg text-zinc-600 dark:text-zinc-300">
                        Drop a file into any channel and let your team preview it instantly. Our centralized file storage makes it easy to find what you need, when you need it.
                    </p>
                    <ul class="space-y-3">
                        <li class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300">
                            <flux:icon name="check-circle" variant="mini" class="text-success-icon" />
                            Support for all major file types
                        </li>
                        <li class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300">
                            <flux:icon name="check-circle" variant="mini" class="text-success-icon" />
                            Secure storage per workspace
                        </li>
                        <li class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300">
                            <flux:icon name="check-circle" variant="mini" class="text-success-icon" />
                            File search across all channels
                        </li>
                    </ul>
                </div>
                <div class="rounded-2xl border border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-zinc-900 aspect-video flex items-center justify-center overflow-hidden">
                     <div class="grid grid-cols-3 gap-4 p-8 w-full h-full">
                         <div class="bg-zinc-200 dark:bg-zinc-800 rounded-lg animate-pulse"></div>
                         <div class="bg-zinc-200 dark:bg-zinc-800 rounded-lg animate-pulse"></div>
                         <div class="bg-zinc-200 dark:bg-zinc-800 rounded-lg animate-pulse"></div>
                     </div>
                </div>
            </div>

            <!-- Feature 3 -->
            <div class="grid gap-12 lg:grid-cols-2 lg:items-center">
                <div class="space-y-6">
                    <x-numerosis::ui.icon-tile icon="bolt" color="purple" />
                    <flux:heading level="2" class="text-3xl font-bold">Automated Workflows</flux:heading>
                    <p class="text-lg text-zinc-600 dark:text-zinc-300">
                        Automate repetitive tasks with our built-in workflow builder. Connect your favorite tools and let {{ config('app.name') }} handle the busy work.
                    </p>
                    <ul class="space-y-3">
                        <li class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300">
                            <flux:icon name="check-circle" variant="mini" class="text-success-icon" />
                            Custom triggers and actions
                        </li>
                        <li class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300">
                            <flux:icon name="check-circle" variant="mini" class="text-success-icon" />
                            Integration with third-party apps
                        </li>
                        <li class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300">
                            <flux:icon name="check-circle" variant="mini" class="text-success-icon" />
                            No-code automation builder
                        </li>
                    </ul>
                </div>
                <div class="rounded-2xl border border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-zinc-900 aspect-video flex items-center justify-center overflow-hidden">
                    <div class="flex flex-col gap-3 p-8 w-full max-w-sm">
                        <div class="h-10 w-full bg-zinc-200 dark:bg-zinc-800 rounded-lg border-2 border-dashed border-zinc-300 dark:border-zinc-700"></div>
                        <div class="h-6 w-4 mx-auto bg-zinc-300 dark:bg-zinc-700 rounded-full"></div>
                        <div class="h-10 w-full bg-zinc-200 dark:bg-zinc-800 rounded-lg border-2 border-dashed border-zinc-300 dark:border-zinc-700"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-12 sm:py-20 bg-zinc-50 dark:bg-zinc-900/50">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 text-center space-y-8">
            <flux:heading level="2" class="text-3xl font-bold">Ready to transform your team's workflow?</flux:heading>
            <p class="text-zinc-600 dark:text-zinc-300 max-w-xl mx-auto">Join thousands of teams who are already building better together.</p>
            @if (\Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Tenancy\RegistrationWizardFeature::NAME))
                <flux:button as="a" variant="primary" href="{{ route('tenants.create') }}" wire:navigate icon="rocket-launch">
                    Create your workspace for free
                </flux:button>
            @endif
        </div>
    </section>
</x-layouts::app>
