<div class="flex flex-col items-center text-center gap-4">
    <flux:icon name="check-circle-2" class="h-12 w-12 text-green-500" />

    <x-numerosis::auth-header
        :title="__('Already accepted')"
        :description="__('This invitation has already been accepted.')"
    />

    <div class="mt-2">
        @if (\Nvade\Numerosis\Support\Features::enabled(\Nvade\Numerosis\Features\Ui\AccountPagesFeature::NAME))
            <flux:link :href="route('tenants.mine')">
                {{ __('Go to my tenants') }} →
            </flux:link>
        @else
            <flux:link :href="route(\Nvade\Numerosis\Support\Routes\RouteNames::home())">
                {{ __('Go home') }} →
            </flux:link>
        @endif
    </div>
</div>
