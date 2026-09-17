<?php

use Illuminate\Support\Facades\Config;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Actions\Tenancy\Domains\ClaimCustomDomain;
use Nvade\Numerosis\Actions\Tenancy\Domains\RecordDomainVerification;
use Nvade\Numerosis\Actions\Tenancy\Domains\VerifyDomainOwnership;
use Nvade\Numerosis\Enums\Tenancy\DomainStatus;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Where a tenant claims a hostname and watches the two records land. The
 * distinction between "we can prove you own it" and "traffic reaches us" is
 * stated in words, because that gap is what support is asked about.
 */
new #[Layout('numerosis-layouts::app')]
class extends Component
{
    public string $hostname = '';

    public ?string $claimError = null;

    /** Scalars, not the model: a hydrated central model re-queries the wrong connection. */
    public ?array $domain = null;

    public function mount(): void
    {
        $this->refreshDomain();
    }

    public function claim(): void
    {
        $this->claimError = null;

        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            return;
        }

        try {
            ClaimCustomDomain::run($tenant, $this->hostname);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->claimError = $e->validator->errors()->first();

            return;
        }

        $this->hostname = '';
        $this->refreshDomain();
    }

    /** The customer pressing "check now" rather than waiting for the sweep. */
    public function recheck(): void
    {
        $domain = $this->currentDomain();

        if ($domain instanceof Domain) {
            RecordDomainVerification::run($domain, VerifyDomainOwnership::run($domain));
        }

        $this->refreshDomain();
    }

    public function regenerateToken(): void
    {
        $tenant = tenant();
        $domain = $this->currentDomain();

        if ($tenant instanceof Tenant && $domain instanceof Domain) {
            ClaimCustomDomain::run($tenant, $domain->domain, regenerate: true);
        }

        $this->refreshDomain();
    }

    public function challengePrefix(): string
    {
        return Config::string('numerosis.tenancy.custom_domains.challenge_prefix', '_numerosis-challenge');
    }

    public function cnameTarget(): string
    {
        $target = Config::get('numerosis.tenancy.custom_domains.cname_target');

        return is_string($target) && $target !== ''
            ? $target
            : Config::string('numerosis.domains.central');
    }

    private function refreshDomain(): void
    {
        $domain = $this->currentDomain();

        $this->domain = $domain === null ? null : [
            'hostname' => $domain->domain,
            'status' => $domain->status->value,
            'status_label' => $domain->status->label(),
            'token' => $domain->verification_token,
            'challenge_host' => $domain->challengeHost(),
            'serving' => $domain->status === DomainStatus::Active,
            'checked' => $domain->last_checked_at?->diffForHumans(),
        ];
    }

    private function currentDomain(): ?Domain
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        /** @var Domain|null $domain */
        $domain = $tenant->domains()->orderByDesc('created_at')->first();

        return $domain;
    }
};
?>
<section class="mx-auto max-w-prose w-full">
    <x-slot:title>{{ __('Custom domain') }}</x-slot:title>

    <div class="flex w-full flex-col gap-4">
        <div>
            <x-numerosis::ui.heading :level="1">{{ __('Custom domain') }}</x-numerosis::ui.heading>
            <x-numerosis::ui.subheading class="mt-1">
                {{ __('Serve this workspace on a hostname you own.') }}
            </x-numerosis::ui.subheading>
        </div>

        @if ($domain === null)
            <x-numerosis::ui.card class="flex flex-col gap-4">
                <flux:input wire:model="hostname" :label="__('Hostname')" placeholder="app.example.com" />

                @if ($claimError)
                    <x-numerosis::ui.text size="sm" class="text-danger-text">{{ $claimError }}</x-numerosis::ui.text>
                @endif

                <flux:button wire:click="claim" variant="primary" class="self-start">
                    {{ __('Claim this domain') }}
                </flux:button>
            </x-numerosis::ui.card>
        @else
            <x-numerosis::ui.card class="flex flex-col gap-4">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-mono text-sm">{{ $domain['hostname'] }}</span>
                    <flux:badge size="sm" :color="$domain['serving'] ? 'green' : 'amber'">
                        {{ $domain['status_label'] }}
                    </flux:badge>
                </div>

                <x-numerosis::ui.text size="sm" variant="subtle">
                    {{ __('Add both records at your DNS provider. The TXT record proves the domain is yours; the CNAME is what makes traffic arrive here. A domain can be verified and still not serve until the CNAME is in place.') }}
                </x-numerosis::ui.text>

                <dl class="grid gap-3 text-sm">
                    <div>
                        <dt class="text-zinc-500">{{ __('TXT record') }}</dt>
                        <dd class="font-mono break-all">{{ $domain['challenge_host'] }} → {{ $domain['token'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('CNAME record') }}</dt>
                        <dd class="font-mono break-all">{{ $domain['hostname'] }} → {{ $this->cnameTarget() }}</dd>
                    </div>
                </dl>

                @if ($domain['checked'])
                    <x-numerosis::ui.text size="sm" variant="subtle">
                        {{ __('Last checked :when.', ['when' => $domain['checked']]) }}
                    </x-numerosis::ui.text>
                @endif

                <div class="flex gap-2">
                    <flux:button wire:click="recheck" variant="primary">{{ __('Check now') }}</flux:button>
                    <flux:button wire:click="regenerateToken" variant="ghost">{{ __('Issue a new token') }}</flux:button>
                </div>
            </x-numerosis::ui.card>
        @endif
    </div>
</section>
