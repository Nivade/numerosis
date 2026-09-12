<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

interface Suspendable
{
    public function isSuspended(): bool;
}
