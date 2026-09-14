<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Cashier\Subscription;
use Nvade\Numerosis\Concerns\Billing\Billable;
use Nvade\Numerosis\Concerns\Tenancy\HasGlobalIdentity;
use Nvade\Numerosis\Contracts\Auth\CentralUserModel;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Contracts\Subscribable;
use Nvade\Numerosis\Contracts\Tenancy\HasTenants;
use Nvade\Numerosis\Models\Tenant as Workspace;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\Observers\Auth\CentralUserObserver;
use Override;
use Stancl\Tenancy\Database\Concerns\CentralConnection;
use Stancl\Tenancy\Database\Concerns\ResourceSyncing;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string $password
 * @property string $global_id
 * @property Carbon|null $email_verified_at
 * @property string|null $remember_token
 * @property Carbon|null $deleted_at
 * @property string|null $stripe_id
 * @property string|null $pm_type
 * @property string|null $pm_last_four
 * @property Carbon|null $trial_ends_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Tenant> $tenants
 * @property-read int|null $tenants_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Subscription> $subscriptions
 * @property-read int|null $subscriptions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SocialAccount> $socialAccounts
 * @property-read int|null $social_accounts_count
 *
 * @mixin Model
 */
#[Table(name: 'users')]
#[ObservedBy(CentralUserObserver::class)]
#[Fillable([
    'name',
    'email',
    'password',
    'global_id',
    'email_verified_at',
    'stripe_id',
    'pm_last_four',
    'pm_type',
    'trial_ends_at',
])]
#[Guarded([
    'id',
])]
#[Hidden([
    'password',
    'remember_token',
])]
class CentralUser extends User implements BillableUser, CentralUserModel, HasTenants, Subscribable
{
    use Billable;
    use CentralConnection;
    use HasGlobalIdentity;
    use ResourceSyncing;
    use SoftDeletes;

    protected $with = [
        'tenants.domains',
    ];

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(Numerosis::model(SocialAccount::class));
    }

    /**
     * @see HasTenants::tenants()
     *
     * @return BelongsToMany<Tenant, Model, Membership, 'pivot'>
     */
    public function tenants(): BelongsToMany
    {
        return self::tenantsRelation($this);
    }

    /**
     * A `Model`-typed parameter, not `$this` directly: `$this` carries the
     * concrete class, which `TDeclaringModel` will not accept against the
     * interface's declared `Model`.
     *
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

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }

    public function getTenantModelName(): string
    {
        return Numerosis::model(Workspace\User::class);
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
        return [
            'name',
            'email',
            'password',
            'email_verified_at',
        ];
    }

    /**
     * @return string|list<string>
     */
    public function guardName(): string|array
    {
        return 'web';
    }

    #[Override]
    public function getForeignKey(): string
    {
        return 'user_id';
    }
}
