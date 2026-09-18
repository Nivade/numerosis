<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Database\Factories\Central\ImpersonationSessionFactory;
use Nvade\Numerosis\Enums\Tenancy\ImpersonationEndReason;
use Nvade\Numerosis\Numerosis;
use Override;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One support session, from minting the token to the moment it ended. The row
 * is the compliance artefact and is never deleted; the activity log is the
 * human-readable half.
 *
 * @property int $id
 * @property string $token
 * @property string $tenant_id
 * @property string $staff_global_id
 * @property string $target_global_id
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property ImpersonationEndReason|null $ended_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read CentralUser|null $staff
 *
 * @mixin Model
 */
#[UseFactory(ImpersonationSessionFactory::class)]
#[Fillable([
    'token',
    'tenant_id',
    'staff_global_id',
    'target_global_id',
    'started_at',
    'ended_at',
    'ended_reason',
])]
class ImpersonationSession extends Model
{
    use CentralConnection;

    /** @use HasFactory<ImpersonationSessionFactory> */
    use HasFactory;

    #[Override]
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'ended_reason' => ImpersonationEndReason::class,
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Numerosis::model(Tenant::class), 'tenant_id', 'id');
    }

    /**
     * @return BelongsTo<CentralUser, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Numerosis::model(CentralUser::class), 'staff_global_id', 'global_id');
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * How long a redeemed session may run before the next request ends it.
     */
    public static function maximumMinutes(): int
    {
        return Config::integer('numerosis.tenancy.impersonation.session_minutes', 60);
    }

    public function hasExpired(): bool
    {
        $startedAt = $this->started_at;

        if ($startedAt === null) {
            return false;
        }

        return $startedAt->addMinutes(self::maximumMinutes())->isPast();
    }

    /**
     * Redeemed, still open, and past the cap. The sweep and the per-request
     * guard both read this state instead of deciding it for themselves.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function stale(Builder $query): Builder
    {
        return $query
            ->whereNull('ended_at')
            ->whereNotNull('started_at')
            ->where('started_at', '<', now()->subMinutes(self::maximumMinutes()));
    }

    public function close(ImpersonationEndReason $reason): void
    {
        if (! $this->isOpen()) {
            return;
        }

        $this->update(['ended_at' => now(), 'ended_reason' => $reason]);
    }
}
