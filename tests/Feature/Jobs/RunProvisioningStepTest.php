<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Jobs;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
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
use RuntimeException;

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

    /**
     * A failed record carries the failing step, its error and its attempt
     * count, and is deliberately not a run: the retry the staff screen fires
     * has to start again at exactly this step.
     */
    public function test_an_exhausted_step_records_its_failure_without_counting_as_run(): void
    {
        $user = CentralUser::factory()->create(['global_id' => 'failing-'.uniqid()]);
        $provision = $this->provisionRow($user, 'recordsfailure-'.uniqid());

        new RunProvisioningStep($provision->slug, CreateTenant::class)
            ->failed(new RuntimeException('disk full'));

        $provision->refresh();

        $record = $provision->step_records[CreateTenant::class];

        $this->assertSame(StepOutcome::Failed->value, $record['outcome']);
        $this->assertSame('disk full', $record['reason'] ?? null);
        $this->assertSame(1, $record['attempts'] ?? null);
        $this->assertFalse($provision->hasRun(CreateTenant::class));
        $this->assertSame(CreateTenant::class, $provision->failedStep());
        $this->assertSame(1, $provision->failedAttempts());
    }

    /**
     * Three done and one failed is the row the retry button acts on: the
     * failed step is where the chain resumes, and the three before it are not
     * re-run.
     */
    public function test_a_row_with_three_done_steps_and_one_failed_resumes_at_the_failed_one(): void
    {
        $user = CentralUser::factory()->create(['global_id' => 'resume-'.uniqid()]);
        $provision = $this->provisionRow($user, 'resumeshere-'.uniqid());

        /** @var list<class-string> $steps */
        $steps = Config::array('numerosis.tenancy.provisioning.steps');

        foreach (array_slice($steps, 0, 3) as $step) {
            $provision->recordStep($step, StepOutcome::Done);
        }

        $failed = $steps[3];
        $provision->recordStep($failed, StepOutcome::Failed, 'exhausted', 5);

        $this->assertSame($failed, $provision->currentStep());
        $this->assertSame($failed, $provision->failedStep());
    }
}
