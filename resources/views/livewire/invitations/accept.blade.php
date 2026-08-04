<div class="flex flex-col gap-6">
    <x-auth-header
        :title="__('Accept Invitation')"
        :description="__('You\'ve been invited by :name to join their organization', ['name' => $invitation->inviter->name])"
    />

    @if (session('error'))
        <flux:callout icon="exclamation-triangle" variant="danger">
            {{ session('error') }}
        </flux:callout>
    @endif

    <div class="rounded-lg border p-4 text-sm text-zinc-700 dark:text-zinc-300">
        <div class="flex flex-col gap-1">
            <div><span class="font-medium">{{ __('Email') }}:</span> {{ $invitation->email }}</div>
            <div><span class="font-medium">{{ __('Role') }}:</span> {{ ucfirst($invitation->role) }}</div>
        </div>
    </div>

    <form wire:submit="accept" class="flex flex-col gap-4">
        @unless($existingUser)
            <flux:input
                id="name"
                name="name"
                type="text"
                wire:model="name"
                :label="__('Full name')"
                required
                autocomplete="name"
                autofocus
            />

            <flux:input
                id="password"
                name="password"
                type="password"
                wire:model="password"
                :label="__('Password')"
                required
                autocomplete="new-password"
                viewable
            />

            <flux:input
                id="password_confirmation"
                name="password_confirmation"
                type="password"
                wire:model="password_confirmation"
                :label="__('Confirm password')"
                required
                autocomplete="new-password"
            />
        @endunless

        <x-turnstile-field />

        <flux:button variant="primary" type="submit" class="w-full">
            {{ $existingUser ? __('Accept Invitation') : __('Create account & accept') }}
        </flux:button>
    </form>

    @if (\App\Support\Features::enabled(\App\Features\Social\SocialLoginFeature::NAME))
        <div class="flex items-center gap-3">
            <div class="h-px grow bg-zinc-200 dark:bg-zinc-800"></div>
            <span class="text-xs text-zinc-500">{{ __('or') }}</span>
            <div class="h-px grow bg-zinc-200 dark:bg-zinc-800"></div>
        </div>

        <x-auth.buttons.grid :invitation="$this->invitation" />
    @endif
</div>
