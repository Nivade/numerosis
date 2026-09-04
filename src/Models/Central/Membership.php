<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Observers\MembershipObserver;
use Nvade\Numerosis\Support\Numerosis;
use Override;
use Stancl\Tenancy\Database\Concerns\CentralConnection;
use Stancl\Tenancy\Database\Models\TenantPivot;

/**
 * @property int $id
 * @property string $tenant_id
 * @property string $global_user_id
 * @property MembershipRole $role
 * @property string|null $invited_by
 * @property Carbon|null $invited_at
 * @property Carbon|null $joined_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read CentralUser $user
 * @property-read CentralUser $inviter
 *
 * @mixin Model
 */
#[Fillable([
    'tenant_id',
    'global_user_id',
    'role',
    'invited_by',
    'invited_at',
    'joined_at',
])]
#[Table(name: 'memberships')]
#[ObservedBy(MembershipObserver::class)]
class Membership extends TenantPivot
{
    use CentralConnection;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    #[Override]
    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Numerosis::model(Tenant::class));
    }

    /**
     * @return BelongsTo<CentralUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Numerosis::model(CentralUser::class));
    }

    /**
     * @return BelongsTo<CentralUser, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(Numerosis::model(CentralUser::class), 'invited_by');
    }

    public function isOwner(): bool
    {
        return $this->role === MembershipRole::Owner;
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, [MembershipRole::Owner, MembershipRole::Admin], true);
    }
}
