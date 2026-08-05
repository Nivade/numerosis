<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Support\Numerosis;
use Stripe\Exception\InvalidRequestException;

#[Description('Delete Stripe customers created during abandoned checkouts that never converted to a subscription')]
#[Signature('billing:prune-orphaned-customers
                            {--hours=48 : Grace period since the Stripe customer was created before it is eligible for pruning}
                            {--dry-run : Report what would be pruned without deleting anything}')]
class PruneOrphanedStripeCustomers extends Command
{
    public function handle(): void
    {
        $hours = (int) $this->option('hours');
        $dryRun = (bool) $this->option('dry-run');

        $centralUserClass = Numerosis::model(CentralUser::class);

        $centralUserClass::query()
            ->whereNotNull('stripe_id')
            ->whereDoesntHave('subscriptions')
            ->chunkById(100, function ($users) use ($hours, $dryRun) {
                foreach ($users as $user) {
                    $this->pruneIfEligible($user, $hours, $dryRun);
                }
            });
    }

    private function pruneIfEligible(CentralUser $user, int $hours, bool $dryRun): void
    {
        $stripeId = $user->stripe_id;

        if ($stripeId === null) {
            return;
        }

        try {
            $customer = Cashier::stripe()->customers->retrieve($stripeId);
        } catch (InvalidRequestException) {
            // Already gone on Stripe's side; just clean up our reference.
            $user->update(['stripe_id' => null]);

            return;
        }

        if (Date::createFromTimestamp($customer->created)->gt(now()->subHours($hours))) {
            return;
        }

        if ($dryRun) {
            $this->line("Would prune Stripe customer {$stripeId} (user #{$user->id})");

            return;
        }

        Cashier::stripe()->customers->delete($stripeId);
        $user->update(['stripe_id' => null]);

        Log::info('Pruned orphaned Stripe customer', [
            'user_id' => $user->id,
            'stripe_id' => $customer->id,
        ]);
    }
}
