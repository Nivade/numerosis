<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvade\Numerosis\Database\Factories\Central\InvitationFactory;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Exceptions\Invitations\InvitationAlreadyAccepted;
use Nvade\Numerosis\Exceptions\Invitations\InvitationExpired;
use Nvade\Numerosis\Models\Concerns\ClaimableOnce;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\Policies\Invitations\InvitationPolicy;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property string $ulid
 * @property string $tenant_id
 * @property string $email
 * @property MembershipRole $role
 * @property int|null $invited_by_user_id
 * @property int|null $accepted_by_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read CentralUser|null $invitedBy
 * @property-read CentralUser|null $acceptedBy
 *
 * @mixin Model
 */
#[Table('tenant_invitations')]
#[Fillable(['tenant_id', 'email', 'role', 'invited_by_user_id', 'expires_at'])]
#[UsePolicy(InvitationPolicy::class)]
#[UseFactory(InvitationFactory::class)]
#[RouteKey('ulid')]
class Invitation extends Model
{
    use CentralConnection;
    use ClaimableOnce, MassPrunable {
        ClaimableOnce::prunable insteadof MassPrunable;
    }

    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

    use LogsActivity;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
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
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(Numerosis::model(CentralUser::class), 'invited_by_user_id');
    }

    /**
     * @return BelongsTo<CentralUser, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(Numerosis::model(CentralUser::class), 'accepted_by_user_id');
    }

    /**
     * Both refusals a visitor can be shown before the row is claimed. The
     * claim itself is a conditional `UPDATE` in `AcceptInvitation`, which is
     * what makes concurrent acceptance safe; this only fails early and readably.
     *
     * @throws InvitationAlreadyAccepted
     * @throws InvitationExpired
     */
    public function assertClaimable(): void
    {
        throw_if($this->isAccepted(), InvitationAlreadyAccepted::class, 'This invitation has already been accepted.');
        throw_if($this->isExpired(), InvitationExpired::class, 'This invitation has expired.');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'email',
                'role',
                'accepted_at',
                'expires_at',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
