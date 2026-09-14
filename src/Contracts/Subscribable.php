<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nvade\Numerosis\Models\Central\Subscription;

interface Subscribable
{
    /**
     * `Model`, not `static`: an interface is not a `Model`, so `static` here
     * resolves to `static(Subscribable)` and `TDeclaringModel` rejects it.
     * Measured 2026-09-14 — `static` on both sides is 5 errors, not 0.
     *
     * @return MorphMany<Subscription, Model>
     */
    public function subscriptions(): MorphMany;

    public function latestSubscription(): ?Subscription;
}
