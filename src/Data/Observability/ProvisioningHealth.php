<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Observability;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** Counts only. This rides in an unauthenticated document, so no slug or name. */
#[MapOutputName(SnakeCaseMapper::class)]
final class ProvisioningHealth extends Data
{
    public function __construct(
        public int $failedLastHour,
        public int $stalled,
        public int $inFlight,
    ) {}
}
