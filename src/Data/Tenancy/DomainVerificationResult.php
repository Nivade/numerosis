<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Tenancy;

use Nvade\Numerosis\Enums\Tenancy\DomainStatus;
use Spatie\LaravelData\Data;

/**
 * Which half of the proof holds. Reported separately on purpose: a domain whose
 * TXT record is right and whose CNAME is missing verifies as owned and still
 * 404s, and telling the customer "verification failed" for that sends them to
 * check the wrong record.
 */
class DomainVerificationResult extends Data
{
    public function __construct(
        public bool $ownershipProven,
        public bool $pointedHere,
        public DomainStatus $status,
        public ?string $reason = null,
    ) {}

    public static function proven(bool $pointedHere): self
    {
        return new self(
            ownershipProven: true,
            pointedHere: $pointedHere,
            status: $pointedHere ? DomainStatus::Active : DomainStatus::Verified,
            reason: $pointedHere ? null : 'dns_not_pointed',
        );
    }

    public static function missingToken(): self
    {
        return new self(false, false, DomainStatus::Verifying, 'txt_missing');
    }

    public static function tokenMismatch(): self
    {
        return new self(false, false, DomainStatus::Verifying, 'txt_mismatch');
    }

    public static function claimedElsewhere(): self
    {
        return new self(false, false, DomainStatus::Failed, 'claimed_elsewhere');
    }

    public function verified(): bool
    {
        return $this->status->isServable();
    }
}
