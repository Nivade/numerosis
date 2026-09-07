<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Nvade\Numerosis\Concerns\Billing\Billable;
use Nvade\Numerosis\Concerns\HasGlobalIdentity;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Contracts\Tenancy\HasTenants;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Support\Numerosis;
use Stancl\Tenancy\Database\Concerns\CentralConnection;
use Stancl\Tenancy\Database\Concerns\ResourceSyncing;

/**
 * A host's own central user: it satisfies the contracts checkout accepts
 * without extending `Models\Central\CentralUser`, which is the arrangement
 * `docs/extending.md` says the role interfaces exist for.
 *
 * Nothing here is production code. It exists so the checkout paths are
 * exercised against a model that only the interfaces describe — they used to
 * narrow on the concrete class and refused this one as a foreign session.
 *
 * @property string|null $stripe_id
 */
#[Table(name: 'users')]
#[Fillable([
    'name',
    'email',
    'password',
    'global_id',
    'email_verified_at',
    'stripe_id',
])]
class HostBillableUser extends User implements BillableUser, HasTenants
{
    use Billable;
    use CentralConnection;
    use HasGlobalIdentity;
    use ResourceSyncing;

    /**
     * @return BelongsToMany<Tenant, Model, Membership, 'pivot'>
     */
    public function tenants(): BelongsToMany
    {
        return self::tenantsRelation($this);
    }

    /**
     * @return BelongsToMany<Tenant, Model, Membership, 'pivot'>
     */
    private static function tenantsRelation(Model $model): BelongsToMany
    {
        return $model->belongsToMany(
            Numerosis::model(Tenant::class),
            'memberships',
            'global_user_id',
            'tenant_id',
            'global_id'
        )
            ->using(Membership::class)
            ->withPivot(['role', 'invited_by', 'invited_at', 'joined_at']);
    }

    public function getTenantModelName(): string
    {
        return Numerosis::model(TenantUser::class);
    }

    public function getCentralModelName(): string
    {
        return static::class;
    }

    /**
     * @return list<string>
     */
    public function getSyncedAttributeNames(): array
    {
        return ['name', 'email', 'password', 'email_verified_at'];
    }

    /**
     * @return string|list<string>
     */
    public function guardName(): string|array
    {
        return 'web';
    }
}
