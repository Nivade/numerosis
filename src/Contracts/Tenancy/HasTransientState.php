<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

/**
 * A registration wizard step holding state that must not reach the session:
 * secrets it re-derives for itself, and request-local UI flags. Anything a
 * step does not list here is persisted, a Stripe client secret included.
 *
 * Static, because the parent asks the configured step classes instead of
 * building a component on every transition.
 */
interface HasTransientState
{
    /**
     * @return list<string>
     */
    public static function transientStateKeys(): array;
}
