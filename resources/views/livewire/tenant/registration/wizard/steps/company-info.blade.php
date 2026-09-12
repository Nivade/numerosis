<div class="space-y-8">
    <x-numerosis::registration.header
        icon="building-office-2"
        title="Company Information"
        description="Tell us about your organization"
    />

    <flux:field>
        <flux:label class="text-sm font-medium">Company Name *</flux:label>
        <flux:input
            wire:model.live.blur="name"
            placeholder="Enter your company name"
            class="mt-1"
            wire:keydown.enter="continue"
        />
        <flux:error name="name"/>
    </flux:field>

    <x-numerosis::registration.navigation
        :show-back="false"
        :disabled="!$name"
    />
</div>
