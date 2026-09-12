<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Jobs;

use Nvade\Numerosis\Actions\Tenancy\CreateTenant;
use Nvade\Numerosis\Jobs\RunProvisioningStep;
use Nvade\Numerosis\Tests\Support\PatientHostStep;
use Nvade\Numerosis\Tests\TestCase;

class RunProvisioningStepTest extends TestCase
{
    public function test_a_step_that_declares_no_profile_takes_the_chain_default(): void
    {
        $job = new RunProvisioningStep('acme', CreateTenant::class);

        $this->assertSame(5, $job->tries);
        $this->assertSame(5, $job->backoff);
    }

    /**
     * @verifies ControlsItsOwnRetries
     *
     * The queue worker reads `$tries`/`$backoff` off the serialized job, so
     * the profile has to be applied when the link is built rather than when
     * it runs.
     */
    public function test_a_step_may_declare_its_own_retry_profile(): void
    {
        $job = new RunProvisioningStep('acme', PatientHostStep::class);

        $this->assertSame(20, $job->tries);
        $this->assertSame(60, $job->backoff);
    }
}
