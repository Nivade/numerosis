<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Tenancy\ProvisioningStep;
use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseManager;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;
use Stancl\Tenancy\Jobs\CreateDatabase;

/**
 * Creates the tenant's physical database, by running stancl's own job.
 *
 * The adapter exists because stancl's jobs take the tenant through the
 * constructor and a zero-argument `handle()`, which is the opposite of what a
 * provisioning step takes. Wrapping is what let the two lists of "steps that
 * run during provisioning" become one.
 */
class CreateTenantDatabase implements ProvisioningStep
{
    use AsAction;

    public function __construct(private readonly TenantDatabaseManager $databases) {}

    public function handle(TenantProvision $provision): void
    {
        $tenant = Numerosis::model(Tenant::class)::findOrFail($provision->slug);

        // Gated per step rather than all-or-nothing: the step record makes a
        // half-built database recoverable, which the old segment-wide guard
        // could not be without re-seeding an already-populated one.
        if ($this->databases->databaseExists($tenant)) {
            return;
        }

        // app()->call(): stancl's handle() takes a container-resolved
        // DatabaseManager, so calling it directly would miss the argument.
        app()->call([new CreateDatabase($tenant), 'handle']);
    }
}
