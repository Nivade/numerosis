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
use Nvade\Numerosis\Support\Numerosis;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Stancl\Tenancy\Database\Concerns\ResourceSyncing;

/**
 * `channels()`/`channelMemberships()` are NOT declared here — they used to
 * be, via `Nvade\Chat\Concerns\HasChatCapabilities`, which made this core
 * model import from an optional app-modules package (removing
 * `app-modules/chat` was a fatal error, not a disabled feature). Moved to
 * `Illuminate\Database\Eloquent\Model::resolveRelationUsing()`, registered
 * by `Nvade\Chat\Providers\ChatServiceProvider::register()` — `$user->channels()`
 * still works when the module is installed, resolves to nothing (the
 * relation simply isn't callable) when it isn't. The `display_status` cast
 * below reads `Nvade\Numerosis\Enums\Tenant\DisplayStatus`, not the module's enum, for
 * the same reason — presence is core (last_seen_at, the 'online' broadcast
 * channel), the vocabulary describing it shouldn't require chat installed.
 * See .claude/plans/opt-in-feature-classes.md, Phase 10.
 *
 * Trade-off accepted deliberately: `resolveRelationUsing()` relations are
 * invisible to PHPStan and IDE completion. `$user->channels` un-typed here
 * is the price of the core -> module import going away.
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
    use LogsActivity;
    use ResourceSyncing;

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
}
