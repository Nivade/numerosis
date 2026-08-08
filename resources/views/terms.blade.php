<x-layouts::app :title="__('Terms of Service')">
    <section class="relative overflow-hidden">
        <x-numerosis::ui.hero-gradient intensity="soft" />

        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 pt-16 pb-12 sm:pt-24 sm:pb-16 relative">
            <div class="space-y-8">
                <div class="space-y-4 text-center">
                    <flux:badge>Legal</flux:badge>
                    <flux:heading level="1" class="text-4xl sm:text-5xl font-extrabold tracking-tight">
                        Terms of Service
                    </flux:heading>
                    <p class="text-zinc-500 dark:text-zinc-400">Last updated: {{ now()->format('F j, Y') }}</p>
                </div>

                <div class="prose dark:prose-invert max-w-none space-y-8 text-zinc-600 dark:text-zinc-300">
                    <section class="space-y-4">
                        <flux:heading level="2" class="text-2xl font-bold">1. Agreement to Terms</flux:heading>
                        <p>
                            By accessing or using {{ config('app.name') }}, you agree to be bound by these Terms of Service. If you disagree with any part of the terms, you may not access the service.
                        </p>
                    </section>

                    <section class="space-y-4">
                        <flux:heading level="2" class="text-2xl font-bold">2. Use License</flux:heading>
                        <p>
                            Permission is granted to temporarily download one copy of the materials (information or software) on {{ config('app.name') }}'s website for personal, non-commercial transitory viewing only.
                        </p>
                        <ul class="list-disc ps-5 space-y-2">
                            <li>Modify or copy the materials;</li>
                            <li>Use the materials for any commercial purpose, or for any public display;</li>
                            <li>Attempt to decompile or reverse engineer any software contained on {{ config('app.name') }}'s website;</li>
                            <li>Remove any copyright or other proprietary notations from the materials;</li>
                        </ul>
                    </section>

                    <section class="space-y-4">
                        <flux:heading level="2" class="text-2xl font-bold">3. Account Responsibility</flux:heading>
                        <p>
                            You are responsible for maintaining the confidentiality of your account and password, including but not limited to the restriction of access to your computer and/or account. You agree to accept responsibility for any and all activities or actions that occur under your account and/or password.
                        </p>
                    </section>

                    <section class="space-y-4">
                        <flux:heading level="2" class="text-2xl font-bold">4. Data Isolation</flux:heading>
                        <p>
                            {{ config('app.name') }} provides per-tenant data isolation. While we take enterprise-grade security measures to ensure your data remains isolated, you are responsible for the content shared within your workspace.
                        </p>
                    </section>

                    <section class="space-y-4">
                        <flux:heading level="2" class="text-2xl font-bold">5. Limitations</flux:heading>
                        <p>
                            In no event shall {{ config('app.name') }} or its suppliers be liable for any damages (including, without limitation, damages for loss of data or profit, or due to business interruption) arising out of the use or inability to use the materials on {{ config('app.name') }}'s website.
                        </p>
                    </section>
                </div>
            </div>
        </div>
    </section>
</x-layouts::app>
