<x-layouts::auth :title="__('Create an account')">
    <div class="flex flex-col gap-6">
        <x-numerosis::auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

        <x-numerosis::ui.auth-session-status class="text-center" :status="session('status')" />

        @if (\Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Auth\SocialLoginFeature::NAME))
            <x-numerosis::auth.social-buttons />
            <x-numerosis::auth.social-divider />
        @endif

        {{-- An invitee arriving through `invitations.show` gets the
             invitation's address prefilled. `ShowInvitationController` stashes
             it, so there is no query here. The field is `readonly`, which is a
             hint and not a control; `AcceptInvitation` re-checks the address
             and throws `InvitationEmailMismatch`. --}}
        @php($invitedEmail = session('pending_invitation.email'))

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="name"
                :value="old('name')"
                :label="__('Name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Full name')"
            />

            <flux:input
                name="email"
                :value="old('email', $invitedEmail)"
                :label="__('Email address')"
                type="email"
                required
                :readonly="$invitedEmail !== null"
                autocomplete="email"
                placeholder="email@example.com"
            />

            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                viewable
            />

            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                viewable
            />

            <x-numerosis::turnstile-field />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full">
                    {{ __('Create account') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            {{ __('Already have an account?') }}
            <flux:link :href="route('login')">{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
