<div class="space-y-8">
    <x-numerosis::registration.header
        icon="globe-alt"
        title="Technical Setup"
        description="Configure your unique workspace identifier that will be used to access your platform"
    />

    <!-- Enhanced Form Field with Better Visual Feedback -->
    <div class="space-y-6">
        <flux:field>
            <flux:label class="text-base font-semibold text-gray-900 dark:text-white flex items-center">
                Workspace Domain
                <span class="ml-1 text-red-500" aria-label="Required field">*</span>
            </flux:label>

            <div class="mt-3 relative group">
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
            </div>

            <x-numerosis::ui.info-box type="warning" title="Important: Choose carefully - this cannot be changed later" class="mt-4">
                <ul class="mt-2 space-y-1">
                    <li class="flex items-center space-x-2">
                        <span class="w-1.5 h-1.5 bg-amber-500 rounded-full"></span>
                        <span>Use only letters, numbers, and hyphens</span>
                    </li>
                    <li class="flex items-center space-x-2">
                        <span class="w-1.5 h-1.5 bg-amber-500 rounded-full"></span>
                        <span>Must be between 3-30 characters</span>
                    </li>
                    <li class="flex items-center space-x-2">
                        <span class="w-1.5 h-1.5 bg-amber-500 rounded-full"></span>
                        <span>Cannot start or end with a hyphen</span>
                    </li>
                </ul>
            </x-numerosis::ui.info-box>

            <flux:error name="domain" id="domain-error" class="mt-2"/>
        </flux:field>

        @if($domain && $errors->missing('domain'))
            <x-numerosis::ui.info-box type="success" title="Your workspace URL will be:">
                <p class="text-lg font-mono mt-1">
                    https://{{ $domain }}.{{ config('numerosis.domains.apex') }}
                </p>
            </x-numerosis::ui.info-box>
        @endif
    </div>

    <x-numerosis::registration.navigation
        :disabled="!$domain || $errors->has('domain')"
    />
</div>
