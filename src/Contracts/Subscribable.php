<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nvade\Numerosis\Models\Central\Subscription;

interface Subscribable
{
    /**
     * @return MorphMany<Subscription, static>
     */
    public function subscriptions(): MorphMany;

    public function latestSubscription(): ?Subscription;
}
