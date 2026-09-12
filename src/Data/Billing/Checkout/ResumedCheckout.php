<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Billing\Checkout;

use Nvade\Numerosis\Enums\Billing\SetupIntentStatus;
use Nvade\Numerosis\Models\Central\TenantProvision;

/**
 * What ResumeCheckout hands back: the reservation, the SetupIntent's client
 * secret to re-mount an Element against, and the SetupIntent's status, so a
 * caller can tell "already succeeded" (settle without rendering an Element)
 * apart from "requires action" instead of collapsing both to a bool.
 */
final readonly class ResumedCheckout
{
    public function __construct(
        public TenantProvision $pending,
        public string $clientSecret,
        public SetupIntentStatus $status,
    ) {}

    public function alreadySucceeded(): bool
    {
        return $this->status === SetupIntentStatus::Succeeded;
    }
}
