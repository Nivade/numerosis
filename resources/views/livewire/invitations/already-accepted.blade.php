<div class="flex flex-col items-center text-center gap-4">
    <flux:icon name="check-circle-2" class="h-12 w-12 text-success-icon" />

    <x-numerosis::auth-header
        :title="__('Already accepted')"
        :description="__('This invitation has already been accepted.')"
    />

    <div class="mt-2">
        @if (\Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Support\Ui\AccountPages::FEATURE))
            <flux:link :href="route(\Nvade\Numerosis\Support\Routes\RouteNames::tenantsMine())">
                {{ __('Go to my tenants') }} →
            </flux:link>
        @else
            <flux:link :href="route(\Nvade\Numerosis\Support\Routes\RouteNames::home())">
                {{ __('Go home') }} →
            </flux:link>
        @endif
    </div>
</div>
