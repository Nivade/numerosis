<x-layouts::app :title="__('About Us')">
    <section class="relative overflow-hidden">
        <div class="absolute inset-0 pointer-events-none bg-[radial-gradient(50%_50%_at_50%_0%,rgba(59,130,246,0.15)_0%,transparent_60%)] dark:bg-[radial-gradient(50%_50%_at_50%_0%,rgba(59,130,246,0.2)_0%,transparent_60%)]"></div>

        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-16 pb-12 sm:pt-24 sm:pb-16">
            <div class="grid gap-12 lg:grid-cols-2 lg:items-center">
                <div class="space-y-6">
                    <flux:badge>Our Story</flux:badge>

                    <flux:heading level="1" class="text-4xl sm:text-5xl font-extrabold tracking-tight">
                        Built for teams who value focus.
                    </flux:heading>

                    <p class="text-lg text-zinc-600 dark:text-zinc-300 max-w-xl">
                        {{ config('app.name') }} started with a simple idea: team collaboration shouldn't be chaotic. We've built a platform that puts organization first, so you can spend less time searching and more time shipping.
                    </p>

                    <div class="space-y-4">
                        <div class="flex gap-4">
                            <div class="flex-none size-10 rounded-lg bg-blue-500/10 flex items-center justify-center">
                                <flux:icon name="user-group" class="text-blue-500" />
                            </div>
                            <div>
                                <h3 class="font-semibold">Team First</h3>
                                <p class="text-zinc-500 dark:text-zinc-400">Designed to help teams of all sizes stay aligned and productive.</p>
                            </div>
                        </div>
                        <div class="flex gap-4">
                            <div class="flex-none size-10 rounded-lg bg-emerald-500/10 flex items-center justify-center">
                                <flux:icon name="shield-check" class="text-emerald-500" />
                            </div>
                            <div>
                                <h3 class="font-semibold">Privacy Conscious</h3>
                                <p class="text-zinc-500 dark:text-zinc-400">Your data is yours. We ensure complete isolation for every workspace.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="relative aspect-square rounded-2xl bg-zinc-100 dark:bg-zinc-800/50 overflow-hidden ring-1 ring-zinc-200 dark:ring-white/10 flex items-center justify-center p-12">
                   <x-app-logo class="size-48 opacity-20" />
                   <div class="absolute inset-0 flex items-center justify-center">
                       <flux:heading level="2" class="text-3xl font-bold">Making work flow.</flux:heading>
                   </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-12 sm:py-20 border-t border-zinc-100 dark:border-white/5">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-16 space-y-4">
                <flux:heading level="2" class="text-3xl font-bold">Our Core Values</flux:heading>
                <p class="text-zinc-500 dark:text-zinc-400">These principles guide everything we build at {{ config('app.name') }}.</p>
            </div>

            <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                <div class="space-y-3">
                    <flux:heading level="3" class="text-xl font-semibold">Simplicity</flux:heading>
                    <p class="text-zinc-600 dark:text-zinc-300">We believe in making complex things feel easy and intuitive.</p>
                </div>
                <div class="space-y-3">
                    <flux:heading level="3" class="text-xl font-semibold">Reliability</flux:heading>
                    <p class="text-zinc-600 dark:text-zinc-300">Our platform is built to be fast, stable, and always available when you need it.</p>
                </div>
                <div class="space-y-3">
                    <flux:heading level="3" class="text-xl font-semibold">Openness</flux:heading>
                    <p class="text-zinc-600 dark:text-zinc-300">We value transparent communication and open collaboration.</p>
                </div>
            </div>
        </div>
    </section>
</x-layouts::app>
