<x-numerosis-layouts::auth :title="__('Verify your code')">
    <div class="flex flex-col gap-6">
        <x-numerosis::auth-header
            :title="__('Enter your one-time code')"
            :description="__('We sent a one-time password to your email address.')"
        />

        <x-numerosis::ui.auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('one-time-password.login.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="code"
                :value="old('code')"
                :label="__('One-time code')"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                required
                autofocus
            />

            <flux:button variant="primary" type="submit" class="w-full">{{ __('Verify') }}</flux:button>
        </form>

        <div class="text-center text-sm text-zinc-600 dark:text-zinc-400">
            <flux:link :href="route('login')">{{ __('Use a different email address') }}</flux:link>
        </div>
    </div>
</x-numerosis-layouts::auth>
