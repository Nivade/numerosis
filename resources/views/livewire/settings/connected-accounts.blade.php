<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <flux:heading>{{ __('Connected accounts') }}</flux:heading>
        <flux:subheading>{{ __('Manage the third-party accounts linked to your login') }}</flux:subheading>
    </div>

    <x-numerosis::ui.auth-session-status :status="session('status')" />

    <div class="space-y-4">
        @foreach (\Nvade\Numerosis\Enums\Auth\SocialProvider::configured() as $provider)
            @php($account = $accounts->firstWhere('provider', $provider))

            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <flux:icon :icon="$provider->icon()" />
                    <span>{{ $provider->label() }}</span>
                </div>

                @if ($account)
                    <form method="POST" action="{{ route('social.destroy', $account) }}">
                        @csrf
                        @method('DELETE')
                        <flux:button type="submit" variant="ghost" size="sm">
                            {{ __('Disconnect') }}
                        </flux:button>
                    </form>
                @else
                    <flux:button
                        :href="route(config('numerosis.social.routes.redirect.name'), $provider->value)"
                        variant="ghost"
                        size="sm"
                    >
                        {{ __('Connect') }}
                    </flux:button>
                @endif
            </div>
        @endforeach
    </div>
</section>
