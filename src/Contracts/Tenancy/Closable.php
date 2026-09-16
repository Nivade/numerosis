<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Illuminate\Support\Carbon;

interface Closable
{
    public function isClosed(): bool;

    /** The date the closed tenant's data becomes eligible for purging, null while it is open. */
    public function purgeAt(): ?Carbon;
}
