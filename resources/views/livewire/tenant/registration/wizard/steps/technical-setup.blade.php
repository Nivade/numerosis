@php
    $mode = \Nvade\Numerosis\Enums\Tenancy\IdentificationMode::current();
    $isSubdomain = $mode === \Nvade\Numerosis\Enums\Tenancy\IdentificationMode::Subdomain;
    $isCustomDomain = $mode === \Nvade\Numerosis\Enums\Tenancy\IdentificationMode::CustomDomain;
    $isPath = $mode === \Nvade\Numerosis\Enums\Tenancy\IdentificationMode::Path;
@endphp

<div class="space-y-8">
    <x-numerosis::registration.header
        icon="globe-alt"
        title="Technical Setup"
        :description="$isPath ? 'Choose the unique identifier used in your workspace URL' : 'Configure your unique workspace identifier that will be used to access your platform'"
    />

    <div class="space-y-6">
        <flux:field>
            <x-numerosis::registration.required-label>
                {{ $isPath ? 'Workspace Identifier' : 'Workspace Domain' }}
            </x-numerosis::registration.required-label>

            <div class="mt-3 relative group">
                @if($isSubdomain)
                    <flux:input.group>
                        <flux:input
                            wire:model.blur.live="domain"
                            placeholder="your-company-name"
                            aria-describedby="domain-help domain-error"
                            wire:keydown.enter="continue"
                        />
                        <flux:input.group.suffix>
                            {{ '.' . config('numerosis.domains.apex') }}
                        </flux:input.group.suffix>
                    </flux:input.group>
                @else
                    <flux:input
                        wire:model.blur.live="domain"
                        placeholder="your-company-name"
                        aria-describedby="domain-help domain-error"
                        wire:keydown.enter="continue"
                    />
                @endif
            </div>

            <x-numerosis::ui.info-box type="warning" title="Important: Choose carefully - this cannot be changed later" class="mt-4">
                <ul class="mt-2 space-y-1">
                    @foreach ([
                        'Use only letters, numbers, and hyphens',
                        'Must be between 3-30 characters',
                        'Cannot start or end with a hyphen',
                    ] as $rule)
                        <li class="flex items-center space-x-2">
                            <span class="w-1.5 h-1.5 bg-warning-icon rounded-full"></span>
                            <span>{{ $rule }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-numerosis::ui.info-box>

            <flux:error name="domain" id="domain-error" class="mt-2"/>
        </flux:field>

        @if($isCustomDomain)
            <flux:field>
                <x-numerosis::registration.required-label>Custom Domain</x-numerosis::registration.required-label>

                <div class="mt-3">
                    <flux:input
                        wire:model.blur.live="customDomain"
                        placeholder="app.yourcompany.com"
                        aria-describedby="customDomain-error"
                        wire:keydown.enter="continue"
                    />
                </div>

                <x-numerosis::ui.info-box type="warning" title="Point this domain at us before continuing" class="mt-4">
                    <p>You'll need to configure DNS for this domain to reach your workspace after setup.</p>
                </x-numerosis::ui.info-box>

                <flux:error name="customDomain" id="customDomain-error" class="mt-2"/>
            </flux:field>
        @endif

        @if($domain && $errors->missing('domain') && ($isSubdomain || $errors->missing('customDomain')))
            <x-numerosis::ui.info-box type="success" title="Your workspace URL will be:">
                <p class="text-lg font-mono mt-1">
                    @if($isSubdomain)
                        https://{{ $domain }}.{{ config('numerosis.domains.apex') }}
                    @elseif($isCustomDomain)
                        https://{{ $customDomain }}
                    @else
                        {{ config('numerosis.domains.central') }}/{{ $domain }}
                    @endif
                </p>
            </x-numerosis::ui.info-box>
        @endif
    </div>

    <x-numerosis::registration.navigation
        :disabled="!$domain || $errors->has('domain') || ($isCustomDomain && (!$customDomain || $errors->has('customDomain')))"
    />
</div>
