<x-layouts::auth :title="__('Forgot password')">
    <div class="flex flex-col gap-6">
        <x-numerosis::auth-header :title="__('Forgot password')" :description="__('Enter your email to receive a password reset link')" />

        <x-numerosis::ui.auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="email"
                :value="old('email')"
                :label="__('Email Address')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <x-numerosis::turnstile-field />

            <flux:button variant="primary" type="submit" class="w-full">{{ __('Email password reset link') }}</flux:button>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-400">
            {{ __('Or, return to') }}
            <flux:link :href="route('login')">{{ __('log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
