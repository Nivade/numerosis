<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Billing;

enum PlanChangeDirection: string
{
    case Upgrade = 'upgrade';
    case Downgrade = 'downgrade';
}
