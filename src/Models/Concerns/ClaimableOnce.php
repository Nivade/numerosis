<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models\Concerns;

use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A row somebody claims once through a link: a ULID route key, an expiry and an
 * `accepted_at` stamp. The claim itself is a conditional `UPDATE` in the action
 * that accepts it, which is what makes concurrent acceptance safe.
 *
 * @phpstan-require-extends Model
 */
trait ClaimableOnce
{
    #[Boot]
    protected static function ulids(): void
    {
        static::creating(function (Model $model): void {
            $model->setAttribute('ulid', $model->getAttribute('ulid') ?? (string) Str::ulid());
        });
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        // 30 days after it was claimed or expired, whichever applies.
        $cutoff = now()->subDays(30);

        return static::query()
            ->where(function (Builder $query) use ($cutoff): void {
                $query->whereNotNull('accepted_at')->where('accepted_at', '<', $cutoff);
            })
            ->orWhere(function (Builder $query) use ($cutoff): void {
                $query->whereNull('accepted_at')->where('expires_at', '<', $cutoff);
            });
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }
}
