<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

/**
 * A step whose failure profile differs from the chain's default of 5 attempts
 * 5 seconds apart.
 *
 * Worth declaring when a step waits on something outside this application.
 * `FinalizeTenantProvisioning` carried 20 attempts before the chain gained a
 * terminal handler, because exhausting it left the UI spinning with no signal.
 */
interface ControlsItsOwnRetries extends ProvisioningStep
{
    /** How many times the step may be attempted before the chain fails. */
    public static function tries(): int;

    /** Seconds to wait between attempts. */
    public static function backoff(): int;
}
