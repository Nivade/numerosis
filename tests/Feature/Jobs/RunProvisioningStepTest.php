<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Jobs;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Tenancy\CreateTenant;
use Nvade\Numerosis\Actions\Tenancy\MarkTenantProvisioned;
use Nvade\Numerosis\Actions\Tenancy\PromoteFirstUserToAdmin;
use Nvade\Numerosis\Enums\Tenancy\StepOutcome;
use Nvade\Numerosis\Exceptions\Tenancy\NoPromotableUser;
use Nvade\Numerosis\Jobs\RunProvisioningStep;
use Nvade\Numerosis\Tests\Concerns\BuildsTenantProvisionData;
use Nvade\Numerosis\Tests\Support\PatientHostStep;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;

class RunProvisioningStepTest extends TestCase
{
    use BuildsTenantProvisionData;
    use RefreshDatabase;

    /**
     * `CreateTenant` implements `ReadsContributions`, not
     * `RequiresContributions` — absence of `CustomDomainContribution` must
     * never skip it, unlike a step declaring `requires()`.
     */
    public function test_a_step_declaring_only_reads_contributions_runs_even_when_the_contribution_is_absent(): void
    {
        $user = CentralUser::factory()->create(['global_id' => 'reads-'.uniqid()]);
        $tenantId = 'reads-tenant-'.uniqid();
        $provision = $this->provisionRow($user, $tenantId);

        new RunProvisioningStep($provision->slug, CreateTenant::class)->handle();

        $this->assertSame(StepOutcome::Done->value, $provision->refresh()->step_records[CreateTenant::class]['outcome']);
        $this->assertDatabaseHas('tenants', ['id' => $tenantId], 'central');
    }

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

    /**
     * A queue worker is one long-lived process calling `handle()` on one job
     * after another — nothing resets between them. `TestCase` forces
     * `queue.default` to `sync`, which hides that: a faked job still runs
     * inline on the request that dispatched it, and every test starts a
     * fresh request, so no test ever has a second job inherit a first job's
     * mess. This calls `handle()` directly, the way a worker would, and
     * checks the state a second job on the same worker would inherit.
     */
    public function test_a_failed_step_does_not_leave_tenancy_initialized_for_the_next_job_on_the_worker(): void
    {
        $tenant = TestTenant::withDatabaseOnly();
        MarkTenantProvisioned::run($tenant);
        $owner = CentralUser::factory()->create();

        // No AddTenantOwner::run(): the tenant database has no non-bot user,
        // so the step throws NoPromotableUser.
        $provision = $this->ownerProvisionRow($tenant, $owner);

        try {
            new RunProvisioningStep($provision->slug, PromoteFirstUserToAdmin::class)->handle();
            $this->fail('Expected NoPromotableUser to be thrown.');
        } catch (NoPromotableUser) {
            // Expected: no promotable user exists in the tenant database.
        }

        $this->assertFalse(
            tenancy()->initialized,
            'A job queued after this one would inherit this step\'s tenant context.',
        );
    }
}
