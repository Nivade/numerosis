<section class="w-full">
    @include('numerosis::partials.settings-heading')

    <x-numerosis::settings.layout :heading="__('Two-factor authentication')" :subheading="__('Add a code from your authenticator app to every sign-in')">
        @if ($enabled)
            <div class="mt-6 space-y-6">
                <flux:badge color="lime">{{ __('Two-factor authentication is on') }}</flux:badge>

                <flux:text>
                    {{ __('You will be asked for a code from your authenticator app the next time you sign in.') }}
                </flux:text>

                @if ($recoveryCodes !== [])
                    <div class="space-y-2">
                        <flux:heading size="sm">{{ __('Recovery codes') }}</flux:heading>
                        <flux:text size="sm">
                            {{ __('Store these somewhere safe. Each one signs you in once if you lose your device.') }}
                        </flux:text>

                        <div class="grid grid-cols-2 gap-2 rounded-lg bg-zinc-100 p-4 font-mono text-sm dark:bg-zinc-800">
                            @foreach ($recoveryCodes as $recoveryCode)
                                <div>{{ $recoveryCode }}</div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <flux:button wire:click="showRecoveryCodes" variant="ghost">
                        {{ __('Show recovery codes') }}
                    </flux:button>
                @endif

                <div class="flex items-center gap-4">
                    <flux:button wire:click="regenerateRecoveryCodes" variant="ghost">
                        {{ __('Regenerate recovery codes') }}
                    </flux:button>

                    <flux:button wire:click="disable" variant="danger">
                        {{ __('Turn off') }}
                    </flux:button>
                </div>
            </div>
        @elseif ($pending)
            <div class="mt-6 space-y-6">
                <flux:text>
                    {{ __('Scan this code with your authenticator app, then enter the six digits it shows.') }}
                </flux:text>

                <div class="inline-block rounded-lg bg-white p-4">
                    {!! $qrCode !!}
                </div>

                <div class="space-y-1">
                    <flux:text size="sm">{{ __('Or enter this key by hand:') }}</flux:text>
                    <div class="font-mono text-sm">{{ $secret }}</div>
                </div>

                <form wire:submit="confirm" class="space-y-6">
                    <flux:input
                        wire:model="code"
                        :label="__('Code')"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        required
                        autofocus
                    />

                    <div class="flex items-center gap-4">
                        <flux:button variant="primary" type="submit">{{ __('Confirm') }}</flux:button>
                        <flux:button wire:click="cancel" variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                    </div>
                </form>
            </div>
        @else
            <div class="mt-6 space-y-6">
                <flux:text>
                    {{ __('Two-factor authentication is off. Turning it on asks for a code from your authenticator app each time you sign in.') }}
                </flux:text>

                <flux:button wire:click="enable" variant="primary">{{ __('Turn on') }}</flux:button>
            </div>
        @endif

        <x-numerosis::ui.action-message class="mt-4" on="two-factor-disabled">
            {{ __('Done.') }}
        </x-numerosis::ui.action-message>
    </x-numerosis::settings.layout>
</section>
