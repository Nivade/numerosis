<div class="flex flex-col gap-6">
    <x-numerosis::auth-header :title="__('Log in to your account')" :description="__('Enter your email to receive a login code')" />

    <!-- Session Status -->
    <x-numerosis::ui.auth-session-status class="text-center" :status="session('status')" />

    <x-numerosis::auth.buttons.grid />

    @if (\Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Social\SocialLoginFeature::NAME))
        <x-numerosis::auth.social-divider />
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

        <x-numerosis::turnstile-field />

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
