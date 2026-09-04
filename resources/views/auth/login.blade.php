{{--
    `OneTimePasswordFeature` replaces the password step of Fortify's login
    pipeline rather than adding a factor after it, so this form must stop
    asking for a password when it is on. `NumerosisLoginRequest` already drops
    `password` from `required` at the same seam; without the matching change
    here the field's own `required` attribute is what blocks an email-only
    submission, and the feature is unreachable from a browser.
--}}
@php($passwordless = \Nvade\Numerosis\Features\Auth\OneTimePasswordFeature::available())

<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-numerosis::auth-header
            :title="__('Log in to your account')"
            :description="$passwordless
                ? __('Enter your email below and we will send you a one-time code')
                : __('Enter your email and password below to log in')"
        />

        <x-numerosis::ui.auth-session-status class="text-center" :status="session('status')" />

        @if (\Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Auth\SocialLoginFeature::NAME))
            <x-numerosis::auth.social-buttons />
            <x-numerosis::auth.social-divider />
        @endif

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="email"
                :value="old('email')"
                :label="__('Email address')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            @unless ($passwordless)
                <div class="relative">
                    <flux:input
                        name="password"
                        :label="__('Password')"
                        type="password"
                        required
                        autocomplete="current-password"
                        :placeholder="__('Password')"
                        viewable
                    />

                    @if (\Illuminate\Support\Facades\Route::has('password.request'))
                        <flux:link class="absolute end-0 top-0 text-sm" :href="route('password.request')">
                            {{ __('Forgot your password?') }}
                        </flux:link>
                    @endif
                </div>
            @endunless

            <x-numerosis::turnstile-field />

            <flux:checkbox name="remember" :label="__('Remember me')" />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full">
                    {{ $passwordless ? __('Send code') : __('Log in') }}
                </flux:button>
            </div>
        </form>

        @unless (tenancy()->initialized)
            @if (\Illuminate\Support\Facades\Route::has('register'))
                <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
                    {{ __('Don\'t have an account?') }}
                    <flux:link :href="route('register')">{{ __('Sign up') }}</flux:link>
                </div>
            @endif
        @endunless
    </div>
</x-layouts::auth>
