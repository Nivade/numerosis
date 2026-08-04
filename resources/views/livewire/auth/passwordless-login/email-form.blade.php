<div class="flex flex-col gap-6">
    <x-auth-header :title="__('Log in to your account')" :description="__('Enter your email to receive a login code')" />

    <!-- Session Status -->
    <x-ui.auth-session-status class="text-center" :status="session('status')" />

    <x-auth.buttons.grid />

    @if (\App\Support\Features::enabled(\App\Features\Social\SocialLoginFeature::NAME))
        <x-auth.social-divider />
    @endif

    <form wire:submit="submitEmail" class="flex flex-col gap-6">
        <!-- Email Address -->
        <flux:input
            wire:model="email"
            :label="__('Email address')"
            type="email"
            required
            autofocus
            autocomplete="email"
            placeholder="email@example.com"
        />

        <x-turnstile-field />

        <!-- Remember Me -->
        <flux:checkbox wire:model="remember" :label="__('Remember me')" />

        <div class="flex items-center justify-end">
            <flux:button variant="primary" type="submit" class="w-full">{{ __('Log in') }}</flux:button>
        </div>
    </form>

    @unless (tenancy()->initialized)
        @if (Route::has('register'))
            <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
                {{ __('Don\'t have an account?') }}
                <flux:link :href="route('register')" wire:navigate="true">{{ __('Sign up') }}</flux:link>
            </div>
        @endif
    @endunless
</div>
