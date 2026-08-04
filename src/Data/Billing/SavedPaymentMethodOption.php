<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Billing;

use Spatie\LaravelData\Data;

final class SavedPaymentMethodOption extends Data
{
    public function __construct(
        public string $id,
        public string $brand,
        public string $last4,
        public int $expMonth,
        public int $expYear,
        public bool $isDefault,
    ) {}
}
