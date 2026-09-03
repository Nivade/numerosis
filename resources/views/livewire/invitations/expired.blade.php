<div class="flex flex-col items-center text-center gap-4">
    <flux:icon name="alert-octagon" class="h-12 w-12 text-danger-icon" />

    <x-numerosis::auth-header
        :title="__('Invitation expired')"
        :description="__('This invitation has expired. Please contact :name to request a new invitation.', ['name' => $invitation->inviter->name])"
    />

    @if (Route::has('login'))
        <div class="mt-2">
            <flux:link :href="route('login')">
                {{ __('Back to login') }} →
            </flux:link>
        </div>
    @endif
</div>
