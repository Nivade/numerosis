<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Observability;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Null is "this driver cannot answer", not zero. Redis, SQS and the null
 * driver each answer a different subset of these three questions.
 */
#[MapInputName(SnakeCaseMapper::class)]
#[MapOutputName(SnakeCaseMapper::class)]
final class QueueHealth extends Data
{
    public function __construct(
        public ?int $depth,
        public ?int $oldestJobSeconds,
        public ?int $failedJobs,
    ) {}
}
