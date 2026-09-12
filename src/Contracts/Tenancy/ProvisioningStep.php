<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Nvade\Numerosis\Models\Central\TenantProvision;

/**
 * One entry in `numerosis.tenancy.provisioning.steps`.
 *
 * Every entry has this one signature and runs as its own link in the queued
 * chain, in list order, so a host can insert a step anywhere — before the
 * database exists, between ownership and billing, after finalization. There is
 * no privileged first or last entry.
 *
 * Steps receive the provision row rather than a payload: it carries the
 * identity, the contributions and the record of which steps already ran, and
 * re-reading it means a step always sees what the step before it wrote.
 *
 * A step is run at most once per provision — {@see ProvisioningStepRecord}
 * skips whatever is already recorded done — so a step does not have to be
 * idempotent for the sake of retries. It still has to be safe to run against
 * a half-built tenant, since the step before it may have failed.
 *
 * Declare {@see ConsumesContributions} to be skipped when the data a step
 * needs was never contributed.
 */
interface ProvisioningStep
{
    public function handle(TenantProvision $provision): void;
}
