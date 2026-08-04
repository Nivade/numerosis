<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Nvade\Numerosis\Enums\TenantProvisionStatus;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

#[Description('Release abandoned domain reservations and alert on tenant provisioning that never completed')]
#[Signature('tenancy:prune-stalled-provisions
                            {--hours=2 : Grace period since the pending provision was created}
                            {--dry-run : Report what would be released or flagged without acting}')]
class PruneStalledTenantProvisions extends Command
{
    public function handle(): void
    {
        $cutoff = now()->subHours((int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');

        $this->releaseAbandonedReservations($cutoff, $dryRun);
        $this->flagStalledProvisions($cutoff, $dryRun);
    }

    /**
     * Reservations whose owner never completed checkout. Expected garbage
     * rather than an incident, so these are dropped quietly and the domain
     * becomes claimable again.
     */
    private function releaseAbandonedReservations(Carbon $cutoff, bool $dryRun): void
    {
        $abandoned = PendingTenantProvision::query()
            ->where('status', TenantProvisionStatus::Reserved)
            ->where('created_at', '<', $cutoff);

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
        PendingTenantProvision::query()
            ->where('status', TenantProvisionStatus::Provisioning)
            ->where('created_at', '<', $cutoff)
            ->each(function (PendingTenantProvision $pending) use ($dryRun) {
                if ($dryRun) {
                    $this->line("Would flag stalled provision {$pending->domain}");

                    return;
                }

                $this->warn("Stalled provision: {$pending->domain}");

                Log::warning('Tenant provisioning stalled', [
                    'domain' => $pending->domain,
                    'global_id' => $pending->global_id,
                    'created_at' => $pending->created_at,
                ]);
            });
    }
}
