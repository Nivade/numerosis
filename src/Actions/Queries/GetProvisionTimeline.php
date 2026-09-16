<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Boot\ConfiguredSteps;
use Nvade\Numerosis\Data\Tenancy\StepTimelineEntry;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;
use Throwable;

/**
 * A provision's `step_records` as an ordered history, including the steps it
 * never reached.
 *
 * The configured list is the spine and recorded steps outside it are appended,
 * so a host that reordered or removed a step still sees what actually ran.
 */
class GetProvisionTimeline
{
    use AsAction;

    /**
     * @return list<StepTimelineEntry>
     */
    public function handle(TenantProvision $provision): array
    {
        $records = $provision->step_records ?? [];

        /** @var list<class-string> $steps */
        $steps = [
            ...ConfiguredSteps::provisioningSteps(),
            ...array_values(array_diff(array_keys($records), ConfiguredSteps::provisioningSteps())),
        ];

        $previous = $provision->provisioning_started_at;
        $entries = [];
        $provisions = Numerosis::model(TenantProvision::class);

        foreach ($steps as $step) {
            $at = $this->timestamp($records[$step]['at'] ?? null);
            $attempts = $records[$step]['attempts'] ?? null;
            $reason = $records[$step]['reason'] ?? null;

            $entries[] = new StepTimelineEntry(
                $step,
                $provisions::labelFor($step),
                $provision->outcomeOf($step),
                is_string($reason) ? $reason : null,
                is_int($attempts) ? $attempts : null,
                $at,
                $at instanceof Carbon && $previous instanceof Carbon ? (int) abs($at->diffInSeconds($previous)) : null,
            );

            $previous = $at ?? $previous;
        }

        return $entries;
    }

    private function timestamp(mixed $at): ?Carbon
    {
        if (! is_string($at)) {
            return null;
        }

        try {
            return Date::parse($at);
        } catch (Throwable) {
            return null;
        }
    }
}
