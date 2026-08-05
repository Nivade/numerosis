<div x-data="{ resendText: '{{ __('one-time-passwords::form.resend_code') }}', isResending: false }" class="flex flex-col gap-6">
    <x-numerosis::auth-header :title="__('one-time-passwords::form.one_time_password_form_title')" :description="null" />

    <form wire:submit="submitOneTimePassword" class="flex flex-col gap-6">
        <flux:input
            wire:model="oneTimePassword"
            :label="__('one-time-passwords::form.password_label')"
            type="text"
            required
            autofocus
            autocomplete="one-time-code"
            placeholder="123456"
        />

        @error('oneTimePassword')
        <p class="-mt-4 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
        @enderror

        <div class="flex flex-col gap-3">
            <flux:button variant="primary" type="submit" class="w-full">
                {{ __('one-time-passwords::form.submit_login_code_button') }}
            </flux:button>

            <button
                type="button"
                @click="
                    if (!isResending) {
                        isResending = true;
                        resendText = 'Code sent';
                        $wire.resendCode();
                        setTimeout(() => {
                            resendText = '{{ __('one-time-passwords::form.resend_code') }}';
                            isResending = false;
                        }, 2000);
                    }
                "
                class="text-sm text-zinc-600 dark:text-zinc-400 self-center cursor-pointer bg-transparent border-0 p-0 m-0 text-left transition-opacity duration-300"
                :class="{ 'underline': !isResending }"
                x-text="resendText"
            ></button>
        </div>
    </form>
</div>
