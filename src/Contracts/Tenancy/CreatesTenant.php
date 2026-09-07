<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * The first entry in `numerosis.tenancy.provisioning.steps`, which has a
 * calling convention of its own: it runs synchronously, before the chain lock,
 * and returns the `Tenant` every later step then receives as
 * `run($tenant, $data): void`.
 *
 * The split is deliberate — step zero is the one step with no tenant to be
 * handed — but nothing used to enforce it, so a host prepending its own step
 * got a `TypeError` inside a queued job, five retries deep and far from the
 * config it edited.
 */
interface CreatesTenant
{
    public function handle(TenantRegistrationData $registration): Tenant;
}
