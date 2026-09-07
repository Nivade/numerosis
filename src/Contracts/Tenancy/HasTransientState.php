<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

/**
 * A registration wizard step holding state that must not reach the session:
 * secrets it re-derives for itself, and request-local UI flags.
 *
 * Which state is transient is the step's knowledge. The wizard parent used to
 * name one step's properties itself, so adding a public property to that step
 * — or replacing it, which `numerosis.tenancy.registration.steps` invites —
 * silently persisted a Stripe client secret.
 *
 * Static because the parent asks the configured step *classes*: building a
 * Livewire component on every transition just to ask is not worth it.
 */
interface HasTransientState
{
    /**
     * @return list<string>
     */
    public static function transientStateKeys(): array;
}
