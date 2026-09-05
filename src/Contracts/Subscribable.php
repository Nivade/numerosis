<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nvade\Numerosis\Models\Central\Subscription;

interface Subscribable
{
    /**
     * `Model`, not `static`: `MorphMany`'s `TDeclaringModel` is invariant, so
     * an interface cannot declare "narrows to whichever class implements
     * this" — only the related model is what implementers' callers narrow.
     *
     * @return MorphMany<Subscription, Model>
     */
    public function subscriptions(): MorphMany;

    public function latestSubscription(): ?Subscription;
}
