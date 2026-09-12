<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nvade\Numerosis\Contracts\Tenancy\ControlsItsOwnRetries;
use Nvade\Numerosis\Contracts\Tenancy\ProvisioningStep;
use Nvade\Numerosis\Contracts\Tenancy\RequiresContributions;
use Nvade\Numerosis\Enums\Tenancy\StepOutcome;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;
use RuntimeException;

/**
 * One link in the provisioning chain: the bookkeeping around a single
 * {@see ProvisioningStep}.
 *
 * Being a link per step is the point. The steps used to run in one job, so a
 * failure in the fifth re-ran the first four, which is why every step had to
 * be idempotent. Here each has its own retries, and a step already recorded
 * done is not run again — a retry resumes rather than restarting.
 */
final class RunProvisioningStep implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $backoff = 5;

    /**
     * @param  class-string<ProvisioningStep>  $step
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $step,
    ) {
        if (is_a($step, ControlsItsOwnRetries::class, true)) {
            $this->tries = $step::tries();
            $this->backoff = $step::backoff();
        }
    }

    public function handle(): void
    {
        $provision = Numerosis::model(TenantProvision::class)::find($this->slug);

        if (! $provision instanceof TenantProvision) {
            throw new RuntimeException("No tenant provision for slug [{$this->slug}].");
        }

        if ($provision->hasRun($this->step)) {
            return;
        }

        $missing = $this->missingContribution($provision);

        if ($missing !== null) {
            $provision->recordStep($this->step, StepOutcome::Skipped, "no {$missing}");

            return;
        }

        resolve($this->step)->handle($provision);

        $provision->recordStep($this->step, StepOutcome::Done);
    }

    /**
     * @return class-string|null The first declared contribution the provision
     *                           does not carry.
     */
    private function missingContribution(TenantProvision $provision): ?string
    {
        if (! is_a($this->step, RequiresContributions::class, true)) {
            return null;
        }

        foreach ($this->step::requires() as $contribution) {
            if ($provision->contribution($contribution) === null) {
                return $contribution;
            }
        }

        return null;
    }
}
