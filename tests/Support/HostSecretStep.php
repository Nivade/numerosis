<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use Illuminate\Contracts\View\View;
use Nvade\Numerosis\Contracts\Tenancy\HasTransientState;
use Spatie\LivewireWizard\Components\StepComponent;

/**
 * A host's own wizard step holding a secret of its own.
 * `numerosis.tenancy.registration.steps` invites exactly this, and the wizard
 * parent used to name one shipped step's properties by hand, so nothing a host
 * added was ever dropped from the session.
 */
class HostSecretStep extends StepComponent implements HasTransientState
{
    public string $keep_me = '';

    public ?string $hostSecret = null;

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
