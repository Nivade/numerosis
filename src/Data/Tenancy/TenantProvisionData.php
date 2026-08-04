<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Tenancy;

use Spatie\LaravelData\Data;

class TenantProvisionData extends Data
{
    public function __construct(
        public TenantRegistrationData $registration,
        public ?string $stripeCustomerId = null,
        public ?string $stripeSubscriptionId = null,
        public ?string $centralUserId = null,
    ) {}
}
