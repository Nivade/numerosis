<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Tenant;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvade\Numerosis\Concerns\HasGlobalIdentity;
use Nvade\Numerosis\Contracts\Auth\TenantUserModel;
use Nvade\Numerosis\Enums\Tenant\DisplayStatus;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\User as BaseUser;
use Nvade\Numerosis\Observers\TenantUserObserver;
use Nvade\Numerosis\Policies\UserPolicy;
use Nvade\Numerosis\Support\Compat\LogsActivityIfInstalled;
use Nvade\Numerosis\Support\Numerosis;
use Override;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Support\LogOptions;
use Stancl\Tenancy\Database\Concerns\ResourceSyncing;

/**
 * A user inside a tenant database, paired with a central user by `global_id`.
 *
 * Modules add their own relations to this model through
 * `Model::resolveRelationUsing()` rather than by editing it, so a module can
 * be removed without breaking the class. Such relations are invisible to
 * static analysis and IDE completion.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $global_id
 * @property Carbon|null $last_seen_at
 * @property DisplayStatus|null $display_status
 * @property string|null $custom_status_text
 * @property Carbon|null $email_verified_at
 * @property string|null $remember_token
 * @property-read Collection<int, Activity> $activities
 * @property-read int|null $activities_count
 *
 * @mixin Model
 */
#[UsePolicy(UserPolicy::class)]
#[Fillable([
    'name',
    'email',
    'password',
    'global_id',
    'last_seen_at',
    'email_verified_at',
    'display_status',
    'custom_status_text',
    'is_bot',
])]
#[ObservedBy(TenantUserObserver::class)]
#[Guarded([
    'id',
])]
#[Hidden([
    'password',
    'remember_token',
])]
class User extends BaseUser implements TenantUserModel
{
    use HasGlobalIdentity;
    use LogsActivityIfInstalled;
    use ResourceSyncing;

    #[Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'display_status' => DisplayStatus::class,
            'is_bot' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }

    /**
     * @return string|list<string>
     */
    public function guardName(): string|array
    {
        return ['tenant'];
    }

    public function getCentralModelName(): string
    {
        return Numerosis::model(CentralUser::class);
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
     * dev-master only: the default implementation is `getSyncedAttributeNames()`
     * verbatim, which does not include the global identifier column. Without
     * this, `UpdateOrCreateSyncedResource`/`CreateTenantResource` create the
     * counterpart record via `$model::withoutEvents(fn () => $model::create(...))`
     * — `withoutEvents` also suppresses the `creating` hook that would
     * otherwise auto-generate one, so the new row's `global_id` stays null,
     * the pivot attach that follows writes a null foreign key, and this
     * package's own `SyncedResourceSaved(In|Changed)*` listeners throw a
     * `TypeError` reading it straight back off the model. v3 has no
     * `getCreationAttributes()` concept at all (its old listener copied
     * every attribute instead — the original `is_bot`-column bug this
     * package already worked around), so this override is inert there. See
     * `.claude/rules/stancl-tenancy-v4.md`.
     *
     * @return list<string>
     */
    public function getCreationAttributes(): array
    {
        return [...$this->getSyncedAttributeNames(), $this->getGlobalIdentifierKeyName()];
    }
}
