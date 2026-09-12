<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;

#[Description('Release abandoned domain reservations, alert on tenant provisioning that never completed, and age out finished rows')]
#[Signature('tenancy:prune-stalled-provisions
                            {--hours=2 : Grace period since the pending provision was created}
                            {--keep-days=30 : How long a completed provision row is kept}
                            {--dry-run : Report what would be released or flagged without acting}')]
class PruneStalledTenantProvisions extends Command
{
    public function handle(): void
    {
        $cutoff = now()->subHours((int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');

        $this->releaseAbandonedReservations($cutoff, $dryRun);
        $this->flagStalledProvisions($cutoff, $dryRun);
        $this->forgetCompletedProvisions(now()->subDays((int) $this->option('keep-days')), $dryRun);
    }

    /**
     * A completed provision is kept only as a record of how the tenant was
     * built: `step_records` is the audit trail, and nothing reads the row once
     * the tenant is live. Without this the table grows one row per tenant for
     * the life of the install, since finishing stopped deleting the row when
     * provisioning gained resumability.
     */
    private function forgetCompletedProvisions(Carbon $cutoff, bool $dryRun): void
    {
        $finished = Numerosis::model(TenantProvision::class)::query()->completedBefore($cutoff);

        if ($dryRun) {
            $this->line("Would forget {$finished->count()} completed provision records");

            return;
        }

        /** @var int $forgotten Eloquent's delete() always returns the affected row count. */
        $forgotten = $finished->delete();

        if ($forgotten > 0) {
            $this->line("Forgot {$forgotten} completed provision records");
        }
    }

    /**
     * Reservations whose owner never completed checkout. Expected garbage
     * and no incident, so these are dropped quietly and the domain becomes
     * claimable again.
     */
    private function releaseAbandonedReservations(Carbon $cutoff, bool $dryRun): void
    {
        $abandoned = Numerosis::model(TenantProvision::class)::query()->reservedBefore($cutoff);

        if ($dryRun) {
            $this->line("Would release {$abandoned->count()} abandoned domain reservations");

            return;
        }

        /** @var int $released Eloquent's delete() always returns the affected row count. */
        $released = $abandoned->delete();

        if ($released > 0) {
            $this->line("Released {$released} abandoned domain reservations");
        }
    }

    /**
     * Paid for, dispatched, and still not finished. This means the queue job
     * never completed and never reached its failure handler either, so it
     * needs a human. No refund or cancellation is attempted here: the customer
     * did pay, and unwinding that automatically is a separate decision.
     */
    private function flagStalledProvisions(Carbon $cutoff, bool $dryRun): void
    {
        Numerosis::model(TenantProvision::class)::query()
            ->provisioningBefore($cutoff)
            ->each(function ($pending) use ($dryRun) {
                /** @var TenantProvision $pending */
                if ($dryRun) {
                    $this->line("Would flag stalled provision {$pending->slug}");

                    return;
                }

                $this->warn("Stalled provision: {$pending->slug}");

                Log::warning('Tenant provisioning stalled', [
                    'slug' => $pending->slug,
                    'global_id' => $pending->global_id,
                    'created_at' => $pending->created_at,
                ]);
            });
    }
}
