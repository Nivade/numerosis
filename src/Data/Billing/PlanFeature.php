<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Billing;

use Spatie\LaravelData\Data;

final class PlanFeature extends Data
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $description,
        public bool $available,
    ) {}
}
