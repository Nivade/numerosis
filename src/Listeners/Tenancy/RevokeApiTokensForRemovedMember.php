<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Listeners\Tenancy;

use Nvade\Numerosis\Concerns\Tenancy\RunsInTenant;
use Nvade\Numerosis\Contracts\Tenancy\TenantDatabaseManager;
use Nvade\Numerosis\Events\Tenancy\MemberRemoved;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\ApiToken;
use Nvade\Numerosis\Models\Tenant\User;
use Nvade\Numerosis\Numerosis;

/**
 * A token outlives the membership it was issued under unless something deletes
 * it, and a revoked member holding a working API key is the same breach as one
 * holding a live session.
 *
 * Runs inside the tenant the member left, because that is the database its
 * tokens live in.
 */
class RevokeApiTokensForRemovedMember
{
    use RunsInTenant;

    public function __construct(private readonly TenantDatabaseManager $databases) {}

    public function handle(MemberRemoved $event): void
    {
        /** @var Tenant|null $tenant */
        $tenant = Numerosis::model(Tenant::class)::find($event->tenantId);

        // A tenant whose database was never created has no token table to read,
        // and initializing tenancy against it fails on connect.
        if (! $tenant instanceof Tenant || ! $this->databases->databaseExists($tenant)) {
            return;
        }

        $this->runInTenant($tenant, function () use ($event): void {
            $userId = Numerosis::model(User::class)::query()
                ->where('global_id', $event->globalUserId)
                ->value('id');

            if ($userId === null) {
                return;
            }

            ApiToken::query()
                ->where('tokenable_type', Numerosis::model(User::class))
                ->where('tokenable_id', $userId)
                ->delete();
        });
    }
}
