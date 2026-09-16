<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Central;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvade\Numerosis\Enums\Tenancy\MigrationRunStatus;
use Nvade\Numerosis\Numerosis;
use Override;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One tenant's leg of one fleet migration run, keyed by `run_id`.
 *
 * Rows outlive the run: `--resume` reads them, and the staff screen is the
 * only place a failure that happened on a worker is visible afterwards.
 *
 * @property int $id
 * @property string $run_id
 * @property string $tenant_id
 * @property MigrationRunStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property list<string>|null $migrations
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant|null $tenant
 *
 * @mixin Model
 */
#[Unguarded]
class TenantMigrationRun extends Model
{
    use CentralConnection;

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Numerosis::model(Tenant::class), 'tenant_id', 'id');
    }

    public function hasFailed(): bool
    {
        return $this->status === MigrationRunStatus::Failed;
    }

    /** How long this leg took, once it finished. */
    public function durationSeconds(): ?int
    {
        if (! $this->started_at instanceof Carbon || ! $this->finished_at instanceof Carbon) {
            return null;
        }

        return (int) abs($this->finished_at->diffInSeconds($this->started_at));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function failedFirst(Builder $query): Builder
    {
        return $query
            ->orderByRaw('case when status = ? then 0 else 1 end', [MigrationRunStatus::Failed->value])
            ->orderBy('tenant_id');
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'status' => MigrationRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'migrations' => 'array',
        ];
    }
}
