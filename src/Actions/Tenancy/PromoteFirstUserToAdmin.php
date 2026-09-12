<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Concerns\Tenancy\RunsInTenant;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionContribution;
use Nvade\Numerosis\Contracts\Tenancy\RequiresContributions;
use Nvade\Numerosis\Data\Tenancy\OwnerContribution;
use Nvade\Numerosis\Events\Auth\AdminGranted;
use Nvade\Numerosis\Exceptions\Tenancy\NoPromotableUser;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Models\Tenant\User;
use Nvade\Numerosis\Numerosis;

/**
 * Makes the tenant's first non-bot user its admin.
 *
 * Its own step rather than a call inside `FinalizeTenantProvisioning`, so the
 * runner decides whether it applies: a tenant provisioned with no owner has
 * nobody to promote, and `NoPromotableUser` keeps meaning what it should —
 * an owner was contributed and the row that should exist does not.
 */
class PromoteFirstUserToAdmin implements RequiresContributions
{
    use AsAction;
    use RunsInTenant;

    /**
     * @return list<class-string<ProvisionContribution>>
     */
    public static function requires(): array
    {
        return [OwnerContribution::class];
    }

    public function handle(TenantProvision $provision): void
    {
        $tenant = $provision->tenant()->firstOrFail();
        $userClass = Numerosis::model(User::class);
        $tenantId = (string) $tenant->getTenantKey();

        $this->runInTenant($tenant, function () use ($userClass, $tenantId) {

            /** @var User|null $user */
            $user = $userClass::where('is_bot', false)->first();

            throw_unless($user, NoPromotableUser::class, 'No non-bot users found');

            $user->assignRole('admin');

            event(new AdminGranted($user->global_id, null, $tenantId));
        });
    }
}
