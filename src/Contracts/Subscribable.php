<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts;

use Nvade\Numerosis\Models\Central\Subscription;
use Illuminate\Database\Eloquent\Relations\MorphMany;

interface Subscribable
{
    /**
     * @return MorphMany<Subscription, static>
     */
    public function subscriptions(): MorphMany;

    public function latestSubscription(): ?Subscription;
}
