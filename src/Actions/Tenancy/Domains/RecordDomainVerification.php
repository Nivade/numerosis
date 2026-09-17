<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy\Domains;

use Illuminate\Support\Facades\Config;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Data\Tenancy\DomainVerificationResult;
use Nvade\Numerosis\Enums\Tenancy\DomainStatus;
use Nvade\Numerosis\Events\Tenancy\DomainRevoked;
use Nvade\Numerosis\Events\Tenancy\DomainVerified;
use Nvade\Numerosis\Models\Central\Domain;

/**
 * Moves a domain to where a check says it belongs, and fires the transition
 * events — once per transition, never once per check, because a listener that
 * calls a certificate API cannot be called hourly for a domain that has not
 * changed.
 *
 * A check that fails inside the verification window leaves the domain retryable:
 * DNS propagates on its own timetable, so "not found yet" is not "not ours".
 *
 * @method static Domain run(Domain $domain, DomainVerificationResult $result)
 */
class RecordDomainVerification
{
    use AsAction;

    public function handle(Domain $domain, DomainVerificationResult $result): Domain
    {
        $wasServable = $domain->isServable();
        $status = $this->statusFor($domain, $result);

        $domain->forceFill([
            'status' => $status,
            'last_checked_at' => now(),
            'verified_at' => $status->isServable() ? ($domain->verified_at ?? now()) : null,
            'verification_failed_at' => $status === DomainStatus::Failed ? now() : null,
        ])->save();

        GlobalCache::store()->forget(CacheKeys::servableDomain($domain->domain));
        GlobalCache::store()->forget(CacheKeys::servableDomains());

        $this->announce($domain, $result, $wasServable, $status);

        return $domain;
    }

    /**
     * A domain that has been retried past the window is marked failed, which is
     * still retryable by hand — the customer may have fixed their zone a week
     * later.
     */
    private function statusFor(Domain $domain, DomainVerificationResult $result): DomainStatus
    {
        if ($result->status->isServable() || $result->status === DomainStatus::Failed) {
            return $result->status;
        }

        $hours = Config::integer('numerosis.tenancy.custom_domains.verification_window_hours', 72);
        $claimedAt = $domain->created_at;

        return $claimedAt !== null && $claimedAt->diffInHours(now()) >= $hours
            ? DomainStatus::Failed
            : $result->status;
    }

    private function announce(
        Domain $domain,
        DomainVerificationResult $result,
        bool $wasServable,
        DomainStatus $status,
    ): void {
        if (! $wasServable && $status->isServable()) {
            event(new DomainVerified($domain->domain, $domain->tenant_id, $result->pointedHere));

            return;
        }

        if ($wasServable && ! $status->isServable()) {
            event(new DomainRevoked($domain->domain, $domain->tenant_id, $result->reason ?? 'verification_lost'));
        }
    }
}
