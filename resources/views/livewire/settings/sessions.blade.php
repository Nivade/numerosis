<section class="w-full">
    @include('numerosis::partials.settings-heading')

    <x-numerosis::settings.layout :heading="__('Browser sessions')" :subheading="__('Review where your account is signed in and sign out anywhere you do not recognise')">
        @unless ($listable)
            <flux:text class="mt-6">
                {{ __('Your application does not store sessions in a way they can be listed, so only signing out everywhere is available here.') }}
            </flux:text>
        @endunless

        @if ($listable)
            <div class="mt-6 space-y-4">
                @forelse ($sessions as $session)
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <span>{{ $this->deviceLabel($session) }}</span>

                                @if ($session->id === $currentSessionId)
                                    <flux:badge size="sm" color="lime">{{ __('This device') }}</flux:badge>
                                @endif

                                @if ($session->tenantId)
                                    <flux:badge size="sm">{{ $tenantNames[$session->tenantId] ?? $session->tenantId }}</flux:badge>
                                @endif
                            </div>

                            <flux:text size="sm">
                                {{ $session->ipAddress ?? __('Unknown IP address') }} &middot;
                                {{ __('Last active :time', ['time' => $session->lastActiveAt->diffForHumans()]) }}
                            </flux:text>
                        </div>

                        @unless ($session->id === $currentSessionId)
                            <flux:button wire:click="revoke('{{ $session->id }}')" variant="ghost" size="sm">
                                {{ __('Sign out') }}
                            </flux:button>
                        @endunless
                    </div>
                @empty
                    <flux:text>{{ __('No other sessions are stored for your account.') }}</flux:text>
                @endforelse
            </div>
        @endif

        <form wire:submit="revokeOthers" class="mt-8 space-y-6">
            <flux:input
                wire:model="password"
                :label="__('Current password')"
                type="password"
                required
                autocomplete="current-password"
            />

            <div class="flex items-center gap-4">
                <flux:button variant="danger" type="submit">{{ __('Sign out other devices') }}</flux:button>

                <x-numerosis::ui.action-message class="me-3" on="sessions-revoked">
                    {{ __('Done.') }}
                </x-numerosis::ui.action-message>
            </div>
        </form>
    </x-numerosis::settings.layout>
</section>
