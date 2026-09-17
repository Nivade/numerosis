<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvade\Numerosis\Exceptions\Tenancy\OwnershipNominationUnavailable;
use Nvade\Numerosis\Models\Concerns\ClaimableOnce;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\Policies\Tenancy\OwnershipNominationPolicy;
use Override;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property string $ulid
 * @property string $tenant_id
 * @property string $nominee_global_id
 * @property string|null $nominated_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read CentralUser|null $nominee
 * @property-read CentralUser|null $nominatedBy
 *
 * @mixin Model
 */
#[Table('tenant_ownership_nominations')]
#[Fillable(['tenant_id', 'nominee_global_id', 'nominated_by', 'expires_at'])]
#[UsePolicy(OwnershipNominationPolicy::class)]
#[RouteKey('ulid')]
class OwnershipNomination extends Model
{
    use CentralConnection;
    use ClaimableOnce, MassPrunable {
        ClaimableOnce::prunable insteadof MassPrunable;
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
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
    public function nominee(): BelongsTo
    {
        return $this->belongsTo(Numerosis::model(CentralUser::class), 'nominee_global_id', 'global_id');
    }

    /**
     * @return BelongsTo<CentralUser, $this>
     */
    public function nominatedBy(): BelongsTo
    {
        return $this->belongsTo(Numerosis::model(CentralUser::class), 'nominated_by', 'global_id');
    }

    /**
     * @throws OwnershipNominationUnavailable
     */
    public function assertClaimable(): void
    {
        throw_if($this->isAccepted(), OwnershipNominationUnavailable::class, 'This ownership transfer has already been accepted.');
        throw_if($this->isExpired(), OwnershipNominationUnavailable::class, 'This ownership transfer has expired.');
    }
}
