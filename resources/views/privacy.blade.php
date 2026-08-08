<x-layouts::app :title="__('Privacy Policy')">
    <section class="relative overflow-hidden">
        <x-numerosis::ui.hero-gradient intensity="soft" />

        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 pt-16 pb-12 sm:pt-24 sm:pb-16 relative">
            <div class="space-y-8">
                <div class="space-y-4 text-center">
                    <flux:badge>Privacy</flux:badge>
                    <flux:heading level="1" class="text-4xl sm:text-5xl font-extrabold tracking-tight">
                        Privacy Policy
                    </flux:heading>
                    <p class="text-zinc-500 dark:text-zinc-400">Last updated: {{ now()->format('F j, Y') }}</p>
                </div>

                <div class="prose dark:prose-invert max-w-none space-y-8 text-zinc-600 dark:text-zinc-300">
                    <p>
                        Your privacy is important to us. It is {{ config('app.name') }}'s policy to respect your privacy regarding any information we may collect from you across our website.
                    </p>

                    <section class="space-y-4">
                        <flux:heading level="2" class="text-2xl font-bold">1. Information We Collect</flux:heading>
                        <p>
                            We only ask for personal information when we truly need it to provide a service to you. We collect it by fair and lawful means, with your knowledge and consent. We also let you know why we’re collecting it and how it will be used.
                        </p>
                    </section>

                    <section class="space-y-4">
                        <flux:heading level="2" class="text-2xl font-bold">2. Use of Information</flux:heading>
                        <p>
                            We use the information we collect in various ways, including to:
                        </p>
                        <ul class="list-disc ps-5 space-y-2">
                            <li>Provide, operate, and maintain our website;</li>
                            <li>Improve, personalize, and expand our website;</li>
                            <li>Understand and analyze how you use our website;</li>
                            <li>Develop new products, services, features, and functionality;</li>
                            <li>Communicate with you for customer service and updates;</li>
                        </ul>
                    </section>

                    <section class="space-y-4">
                        <flux:heading level="2" class="text-2xl font-bold">3. Data Isolation and Storage</flux:heading>
                        <p>
                            We only retain collected information for as long as necessary to provide you with your requested service. What data we store, we’ll protect within commercially acceptable means to prevent loss and theft, as well as unauthorized access, disclosure, copying, use or modification.
                        </p>
                        <p>
                            As a multi-tenant platform, your data is isolated at the database level to ensure maximum privacy and security between different workspaces.
                        </p>
                    </section>

                    <section class="space-y-4">
                        <flux:heading level="2" class="text-2xl font-bold">4. Cookies</flux:heading>
                        <p>
                            We use “cookies” to collect information about you and your activity across our site. A cookie is a small piece of data that our website stores on your computer, and accesses each time you visit, so we can understand how you use our site.
                        </p>
                    </section>

                    <section class="space-y-4">
                        <flux:heading level="2" class="text-2xl font-bold">5. Contact Us</flux:heading>
                        <p>
                            If you have any questions about how we handle user data and personal information, feel free to contact us.
                        </p>
                    </section>
                </div>
            </div>
        </div>
    </section>
</x-layouts::app>
