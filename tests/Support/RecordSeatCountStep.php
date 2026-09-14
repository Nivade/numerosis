<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use Nvade\Numerosis\Contracts\Tenancy\ProvisionContribution;
use Nvade\Numerosis\Contracts\Tenancy\RequiresContributions;
use Nvade\Numerosis\Models\Central\TenantProvision;

/**
 * A host's own provisioning step, reading a contribution core knows nothing
 * about. The far end of the seam `HostSecretStep` starts.
 */
final class RecordSeatCountStep implements RequiresContributions
{
    /** @var array<string, int> Seats seen, keyed by slug. */
    public static array $seen = [];

    /**
     * @return list<class-string<ProvisionContribution>>
     */
    public static function requires(): array
    {
        return [SeatCountContribution::class];
    }

    public function handle(TenantProvision $provision): void
    {
        $seats = $provision->contribution(SeatCountContribution::class);

        if ($seats instanceof SeatCountContribution) {
            self::$seen[$provision->slug] = $seats->seats;
        }
    }
}
