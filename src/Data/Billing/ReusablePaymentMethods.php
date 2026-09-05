<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Billing;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

final class ReusablePaymentMethods extends Data
{
    /**
     * @param  Collection<int, SavedPaymentMethodOption>  $options
     */
    public function __construct(
        #[DataCollectionOf(SavedPaymentMethodOption::class)]
        public Collection $options,
        public bool $fetchFailed = false,
    ) {}
}
