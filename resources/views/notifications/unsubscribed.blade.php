<x-numerosis-layouts::auth>
    <div class="space-y-4 text-center">
        <x-numerosis::ui.heading :level="1">
            {{ $type->mayDisableMail() ? __('You are unsubscribed') : __('This one stays on') }}
        </x-numerosis::ui.heading>

        <x-numerosis::ui.text size="sm" variant="subtle">
            @if ($type->mayDisableMail())
                {{ __('We will stop emailing you about: :label. Everything else is unchanged, and you can turn this back on in your notification settings.', ['label' => $type->label()]) }}
            @else
                {{ __(':label tells you about money or account security, so it cannot be switched off. Nothing was changed.', ['label' => $type->label()]) }}
            @endif
        </x-numerosis::ui.text>
    </div>
</x-numerosis-layouts::auth>
