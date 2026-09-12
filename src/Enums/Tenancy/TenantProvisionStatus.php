<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Tenancy;

/**
 * Where a provision is in its lifecycle, and nothing else. Payment settlement
 * used to live here as an `AwaitingPayment` case, which made it mutually
 * exclusive with `Provisioning` even though both were true at once; it is
 * `tenant_provisions.settled_at` now. Keeping this a pure lifecycle is what
 * lets a conditional update on the column serialize concurrent attempts.
 */
enum TenantProvisionStatus: string
{
    case Reserved = 'reserved';
    case Provisioning = 'provisioning';
    case Completed = 'completed';
    case Failed = 'failed';
}
