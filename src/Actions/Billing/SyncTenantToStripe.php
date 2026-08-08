<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing;

use Illuminate\Contracts\Queue\ShouldQueue;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Nvade\Numerosis\Models\Central\Tenant;

class SyncTenantToStripe implements ShouldQueue
{
    use AsAction;

    public int $jobTries = 3;

    /** @var array<int, int> */
    public array $jobBackoff = [10, 30];

    public function configureJob(JobDecorator $job): void
    {
        $job->onQueue('stripe');
    }

    public function handle(Tenant $tenant): void
    {
        if (! $tenant->hasStripeId()) {
            return;
        }

        $tenant->updateStripeCustomer([
            'name' => $tenant->name,
            'email' => $tenant->stripeEmail(),
        ]);
    }
}
