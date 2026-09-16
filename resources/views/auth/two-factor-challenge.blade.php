<x-numerosis-layouts::auth :title="__('Two-factor authentication')">
    <div class="flex flex-col gap-6" x-data="{ recovery: false }">
        <x-numerosis::auth-header
            :title="__('Confirm it is you')"
            :description="__('Enter the code from your authenticator app.')"
        />

        <x-numerosis::ui.auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('two-factor.login.store') }}" class="flex flex-col gap-6">
            @csrf

            <div x-show="! recovery">
                <flux:input
                    name="code"
                    :label="__('Code')"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    autofocus
                />
            </div>

            <div x-show="recovery" x-cloak>
                <flux:input
                    name="recovery_code"
                    :label="__('Recovery code')"
                    type="text"
                    autocomplete="one-time-code"
                />
            </div>

            <flux:button variant="primary" type="submit" class="w-full">{{ __('Verify') }}</flux:button>
        </form>

        <div class="text-center text-sm text-zinc-600 dark:text-zinc-400">
            <flux:link href="#" x-on:click.prevent="recovery = ! recovery">
                <span x-show="! recovery">{{ __('Use a recovery code') }}</span>
                <span x-show="recovery" x-cloak>{{ __('Use your authenticator app') }}</span>
            </flux:link>
        </div>
    </div>
</x-numerosis-layouts::auth>
