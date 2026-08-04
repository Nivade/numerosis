<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;

// See .claude/rules/tenant-provisioning.md.
interface ProvisionsTenant
{
    public function queue(TenantProvisionData $data): void;
}
