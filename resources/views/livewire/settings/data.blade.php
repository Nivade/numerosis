<section class="w-full">
    @include('numerosis::partials.settings-heading')

    <x-numerosis::settings.layout :heading="__('Your data')" :subheading="__('Ask for a copy of everything this account holds')">
        @if ($status !== '')
            <flux:text class="mt-6">{{ $status }}</flux:text>
        @endif

        <flux:text class="mt-6">
            {{ __('We will collect your account details and the rows that name you inside each workspace you belong to, then email you a link. The link works once and expires.') }}
        </flux:text>

        <div class="mt-6">
            <flux:button wire:click="requestExport" variant="primary">
                {{ __('Request my data') }}
            </flux:button>
        </div>

        @if ($requests->isNotEmpty())
            <div class="mt-8 space-y-3">
                <flux:heading size="sm">{{ __('Previous requests') }}</flux:heading>

                @foreach ($requests as $request)
                    <div class="flex items-center justify-between gap-4">
                        <flux:text size="sm">
                            {{ $request->created_at?->diffForHumans() }}
                        </flux:text>

                        <flux:badge size="sm">{{ $request->status->value }}</flux:badge>
                    </div>
                @endforeach
            </div>
        @endif
    </x-numerosis::settings.layout>
</section>
