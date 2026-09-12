<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use Nvade\Numerosis\Contracts\Tenancy\ProvisionContribution;
use Spatie\LaravelData\Data;

/**
 * A host's own contribution, with no columns on `tenant_provisions` to store
 * it in. Stands in for the case the seam exists for: data core knows nothing
 * about, travelling from wherever a host collects it to a step a host wrote.
 */
final class SeatCountContribution extends Data implements ProvisionContribution
{
    public function __construct(
        public int $seats = 1,
        public ?string $tier = null,
    ) {}
}
