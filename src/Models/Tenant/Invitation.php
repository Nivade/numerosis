<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Tenant;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Nvade\Numerosis\Database\Factories\Tenant\InvitationFactory;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Policies\InvitationPolicy;
use Nvade\Numerosis\Support\Compat\LogsActivityIfInstalled;
use Nvade\Numerosis\Support\Numerosis;
use Override;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $tenant_id
 * @property int $invited_by
 * @property string $email
 * @property string $role
 * @property string $token
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $inviter
 * @property-read Tenant $tenant
 * @property-read Collection<int, Activity> $activities
 * @property-read int|null $activities_count
 *
 * @mixin Model
 */
#[UsePolicy(InvitationPolicy::class)]
#[Fillable([
    'tenant_id',
    'invited_by',
    'email',
    'role',
    'token',
    'expires_at',
    'accepted_at',
])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

    use LogsActivityIfInstalled;

    #[Override]
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    #[Override]
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $invitation): void {
            if (empty($invitation->token)) {
                $invitation->token = Str::random(32);
            }
            if (empty($invitation->expires_at)) {
                $invitation->expires_at = now()->addDays(7);
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(Numerosis::model(User::class), 'invited_by');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Numerosis::model(Tenant::class));
    }

    public function isExpired(): bool
    {
        return Date::parse($this->expires_at)->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }
}
