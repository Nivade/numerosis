<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Nvade\Numerosis\Contracts\Tenancy\ControlsItsOwnRetries;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionContribution;
use Nvade\Numerosis\Contracts\Tenancy\ProvisioningStep;
use Nvade\Numerosis\Contracts\Tenancy\RequiresContributions;
use Nvade\Numerosis\Enums\Tenancy\StepOutcome;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;
use RuntimeException;
use Throwable;

/**
 * One link in the provisioning chain: the bookkeeping around a single
 * {@see ProvisioningStep}.
 *
 * One link per step, so each has its own retries and a step already recorded
 * done is not run again: a retry resumes where the chain stopped.
 */
final class RunProvisioningStep implements ShouldQueue
{
    use Queueable;

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

        $provision->recordStep($this->step, StepOutcome::Done, attempts: $this->attempts());
    }

    /**
     * Writes the failing step onto the row once the retries are spent. The
     * record is not a run: {@see TenantProvision::hasRun()} rejects a failed
     * outcome, so a retried chain starts again at this step.
     */
    public function failed(?Throwable $e): void
    {
        $provision = Numerosis::model(TenantProvision::class)::find($this->slug);

        // A sync chain runs each link inside the one before it, so the throw
        // bubbles through every earlier link and fails each of them too.
        if (! $provision instanceof TenantProvision || $provision->hasRun($this->step)) {
            return;
        }

        $provision->recordStep(
            $this->step,
            StepOutcome::Failed,
            $e?->getMessage(),
            max($this->attempts(), 1),
        );
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
            if (! $provision->contribution($contribution) instanceof ProvisionContribution) {
                return $contribution;
            }
        }

        return null;
    }
}
