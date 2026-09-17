<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Tenancy;

/**
 * Where a domain is between "claimed" and "serving traffic".
 *
 * `Verified` and `Active` are deliberately separate: ownership of the zone is
 * proven long before traffic actually arrives at this platform, and the gap
 * between the two is where every support ticket about a custom domain lives.
 *
 * A subdomain needs no proof, so rows created under subdomain mode are `Active`
 * from the start.
 */
enum DomainStatus: string
{
    case Pending = 'pending';
    case Verifying = 'verifying';
    case Verified = 'verified';
    case Active = 'active';
    case Failed = 'failed';
    case Revoked = 'revoked';

    /** Whether traffic for this hostname may be served, which is what TLS asks. */
    public function isServable(): bool
    {
        return $this === self::Verified || $this === self::Active;
    }

    /** Whether a checker should still be looking at it. */
    public function isCheckable(): bool
    {
        return $this !== self::Revoked;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for DNS records',
            self::Verifying => 'Checking DNS records',
            self::Verified => 'Ownership verified',
            self::Active => 'Live',
            self::Failed => 'Verification failed',
            self::Revoked => 'Revoked',
        };
    }
}
