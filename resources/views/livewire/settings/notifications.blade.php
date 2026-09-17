<section class="mx-auto max-w-prose w-full">
    <x-slot:title>{{ __('Notifications') }}</x-slot:title>

    <div class="flex w-full flex-col gap-4">
        <div>
            <x-numerosis::ui.heading :level="1">{{ __('Notifications') }}</x-numerosis::ui.heading>
            <x-numerosis::ui.subheading class="mt-1">
                {{ __('Choose how each kind of notification reaches you.') }}
            </x-numerosis::ui.subheading>
        </div>

        @if ($status)
            <x-numerosis::ui.text size="sm">{{ $status }}</x-numerosis::ui.text>
        @endif

        <x-numerosis::ui.card class="flex flex-col gap-4">
            @foreach ($types as $type)
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="text-sm font-medium">{{ $type->label() }}</div>

                        @unless ($type->mayDisableMail())
                            <div class="text-xs text-zinc-500">
                                {{ __('Email stays on: this one tells you about money or account security, and missing it costs you something.') }}
                            </div>
                        @endunless
                    </div>

                    <div class="flex shrink-0 gap-4">
                        <flux:checkbox
                            wire:model="preferences.{{ $type->value }}.mail"
                            :label="__('Email')"
                            :disabled="! $type->mayDisableMail()"
                        />

                        <flux:checkbox
                            wire:model="preferences.{{ $type->value }}.database"
                            :label="__('In app')"
                        />
                    </div>
                </div>
            @endforeach

            <flux:button wire:click="save" variant="primary" class="self-start">
                {{ __('Save preferences') }}
            </flux:button>
        </x-numerosis::ui.card>
    </div>
</section>
