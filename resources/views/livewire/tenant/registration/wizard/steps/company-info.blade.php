<div class="space-y-8">
    <x-registration.header
        icon="building-office-2"
        title="Company Information"
        description="Tell us about your organization"
    />

    <flux:field>
        <flux:label class="text-sm font-medium">Company Name *</flux:label>
        <flux:input
            wire:model.live.blur="company_name"
            placeholder="Enter your company name"
            class="mt-1"
            wire:keydown.enter="continue"
        />
        <flux:error name="company_name"/>
    </flux:field>

    <x-registration.navigation
        :show-back="false"
        :disabled="!$company_name"
    />
</div>
