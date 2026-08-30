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
use Nvade\Numerosis\Observers\MembershipObserver;
use Nvade\Numerosis\Support\Compat\Tenancy\PivotWithCentralResource;
use Nvade\Numerosis\Support\Compat\Tenancy\TenantPivot;
use Nvade\Numerosis\Support\Numerosis;
use Override;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property string $tenant_id
 * @property string $global_user_id
 * @property string $role
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
class Membership extends TenantPivot implements PivotWithCentralResource
{
    use CentralConnection;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    #[Override]
    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    /**
     * The central-side resource this pivot syncs — `CentralUser`, never
     * `Tenant`. Satisfies `PivotWithCentralResource` on dev-master; a no-op
     * declaration on v3, which has no such interface to satisfy.
     */
    public function getCentralResourceClass(): string
    {
        return Numerosis::model(CentralUser::class);
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
        return $this->role === 'owner';
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }
}
