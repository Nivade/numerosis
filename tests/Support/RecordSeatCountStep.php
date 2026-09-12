<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use Nvade\Numerosis\Contracts\Tenancy\ConsumesContributions;
use Nvade\Numerosis\Models\Central\TenantProvision;

/**
 * A host's own provisioning step, reading a contribution core knows nothing
 * about. The far end of the seam `HostSecretStep` starts.
 */
final class RecordSeatCountStep implements ConsumesContributions
{
    /** @var array<string, int> Seats seen, keyed by slug. */
    public static array $seen = [];

    /**
     * @return list<class-string<\Nvade\Numerosis\Contracts\Tenancy\ProvisionContribution>>
     */
    public static function consumes(): array
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
