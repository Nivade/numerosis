<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Tenancy;

use Illuminate\Support\Carbon;
use Nvade\Numerosis\Enums\Tenancy\StepOutcome;
use Spatie\LaravelData\Data;

/**
 * One row of a provision's history. A null `$outcome` is a step the chain has
 * not reached, which is every step of a reservation that was never dispatched.
 */
final class StepTimelineEntry extends Data
{
    public function __construct(
        public string $step,
        public string $label,
        public ?StepOutcome $outcome,
        public ?string $reason,
        public ?int $attempts,
        public ?Carbon $at,
        public ?int $durationSeconds,
    ) {}
}
