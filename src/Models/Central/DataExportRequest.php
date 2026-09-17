<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Nvade\Numerosis\Enums\Auth\DataExportStatus;
use Nvade\Numerosis\Numerosis;
use Override;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One subject access request. The artefact is deleted by the retention sweep
 * long before the row is, so a completed request whose `path` no longer
 * resolves is expected rather than a fault.
 *
 * @property int $id
 * @property string $ulid
 * @property string $global_user_id
 * @property string|null $tenant_id
 * @property DataExportStatus $status
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $failure_reason
 * @property Carbon|null $completed_at
 * @property Carbon|null $downloaded_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CentralUser|null $subject
 *
 * @mixin Model
 */
#[Fillable([
    'ulid',
    'global_user_id',
    'tenant_id',
    'status',
    'disk',
    'path',
    'failure_reason',
    'completed_at',
    'downloaded_at',
    'expires_at',
])]
#[RouteKey('ulid')]
class DataExportRequest extends Model
{
    use CentralConnection;

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            $request->ulid ??= (string) Str::ulid();
        });
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'status' => DataExportStatus::class,
            'completed_at' => 'datetime',
            'downloaded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CentralUser, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Numerosis::model(CentralUser::class), 'global_user_id', 'global_id');
    }

    /** Whether the single-use link is still worth following. */
    public function isDownloadable(): bool
    {
        return $this->status === DataExportStatus::Completed
            && $this->path !== null
            && $this->downloaded_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function forSubject(Builder $query, string $globalUserId): void
    {
        $query->where('global_user_id', $globalUserId);
    }
}
