<div class="flex flex-col items-center text-center gap-4">
    <flux:icon name="check-circle-2" class="h-12 w-12 text-success-icon" />

    <x-numerosis::auth-header
        :title="__('Already accepted')"
        :description="__('This invitation has already been accepted.')"
    />

    <div class="mt-2">
        <flux:link :href="route(\Nvade\Numerosis\Support\Routes\RouteNames::tenantsMine())">
            {{ __('Go to my tenants') }} →
        </flux:link>
    </div>
</div>
