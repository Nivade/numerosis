<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Billing;

use Spatie\LaravelData\Data;

final class SavedBillingDetails extends Data
{
    public function __construct(
        public ?string $line1 = null,
        public ?string $line2 = null,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $postalCode = null,
        public ?string $country = null,
        public ?string $name = null,
        public ?string $vatNumber = null,
        public bool $fetchFailed = false,
    ) {}

    public function hasAddress(): bool
    {
        return $this->line1 !== null || $this->country !== null;
    }
}
