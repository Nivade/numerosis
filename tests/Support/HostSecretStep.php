<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use Illuminate\Contracts\View\View;
use Nvade\Numerosis\Contracts\Tenancy\ContributesProvisionData;
use Nvade\Numerosis\Contracts\Tenancy\HasTransientState;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionContribution;
use Spatie\LivewireWizard\Components\StepComponent;

/**
 * A host's own wizard step holding a secret of its own.
 * `numerosis.tenancy.registration.steps` invites exactly this, and the wizard
 * parent used to name one shipped step's properties by hand, so nothing a host
 * added was ever dropped from the session.
 */
class HostSecretStep extends StepComponent implements ContributesProvisionData, HasTransientState
{
    public string $keep_me = '';

    public ?string $hostSecret = null;

    public int $seats = 1;

    /**
     * A host's own collected data reaching provisioning, through the seam
     * core's own steps use.
     */
    public static function contribute(array $state): ?ProvisionContribution
    {
        $seats = $state['seats'] ?? null;

        return is_int($seats) ? new SeatCountContribution(seats: $seats) : null;
    }

    /**
     * @return list<string>
     */
    public static function transientStateKeys(): array
    {
        return ['hostSecret'];
    }

    /**
     * @return array<string, string>
     */
    public function stepInfo(): array
    {
        return ['label' => 'Host Step'];
    }

    public function render(): View
    {
        return view('numerosis::livewire.tenant.registration.wizard.steps.company-info');
    }
}
