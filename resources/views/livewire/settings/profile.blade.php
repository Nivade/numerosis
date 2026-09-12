@use(\Nvade\Numerosis\Enums\Auth\VerificationNotice)

<section class="w-full">
    @include('numerosis::partials.settings-heading')

    <x-numerosis::settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail &&! auth()->user()->hasVerifiedEmail())
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                        @if (session('status') === VerificationNotice::LinkSent->value)
                            <flux:text class="mt-2 font-medium !text-success-icon">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full">{{ __('Save') }}</flux:button>
                </div>

                <x-numerosis::ui.action-message class="me-3" on="profile-updated">
                    {{ __('Saved.') }}
                </x-numerosis::ui.action-message>
            </div>
        </form>

        <livewire:settings.delete-user-form />
    </x-numerosis::settings.layout>
</section>
