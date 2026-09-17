<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Boot;

use Illuminate\Support\Facades\Schema;
use LogicException;
use Nvade\Numerosis\Data\Billing\MeterDefinitionData;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Numerosis;

/**
 * A limit is only a limit if it is a number. `options.max_users` and
 * `options.limits.*` are host-editable JSON, and a typo there reads as
 * uncapped — the plan is sold as limited and enforces nothing.
 */
final class PlanMetadata
{
    private function __construct() {}

    public static function assertWellShaped(): void
    {
        $failures = self::check()['failures'];

        throw_if($failures !== [], new LogicException(implode(' ', $failures)));
    }

    /**
     * @return array{failures: list<string>, warnings: list<string>}
     */
    public static function check(): array
    {
        $failures = [];
        $warnings = [];

        if (! self::tableExists()) {
            return ['failures' => $failures, 'warnings' => $warnings];
        }

        foreach (Numerosis::model(PaymentPlan::class)::query()->get() as $plan) {
            $options = $plan->metadata()['options'] ?? [];

            if (! is_array($options)) {
                $failures[] = "Plan [{$plan->slug}] has a non-array `metadata.options`.";

                continue;
            }

            if (array_key_exists('max_users', $options) && ! is_numeric($options['max_users'])) {
                $failures[] = "Plan [{$plan->slug}] has a non-numeric `metadata.options.max_users`, which reads as uncapped.";
            }

            $limits = $options['limits'] ?? [];

            if (! is_array($limits)) {
                $failures[] = "Plan [{$plan->slug}] has a non-array `metadata.options.limits`.";

                continue;
            }

            foreach ($limits as $capability => $value) {
                if (! is_numeric($value)) {
                    $failures[] = "Plan [{$plan->slug}] has a non-numeric limit for `{$capability}`, which reads as uncapped.";
                }
            }

            self::checkMeters($plan, $options, $failures, $warnings);
        }

        return ['failures' => $failures, 'warnings' => $warnings];
    }

    /**
     * A meter that fails to parse meters nothing, which under-bills silently.
     * `meter_id` is a warning rather than a failure: reporting works without
     * it, reconciliation is what needs it.
     *
     * @param  array<array-key, mixed>  $options
     * @param  list<string>  $failures
     * @param  list<string>  $warnings
     */
    private static function checkMeters(PaymentPlan $plan, array $options, array &$failures, array &$warnings): void
    {
        $declared = $options['meters'] ?? null;

        if ($declared === null) {
            return;
        }

        if (! is_array($declared)) {
            $failures[] = "Plan [{$plan->slug}] has a non-array `metadata.options.meters`.";

            return;
        }

        foreach ($declared as $index => $entry) {
            $meter = is_array($entry) ? MeterDefinitionData::tryFrom($entry) : null;

            if (! $meter instanceof MeterDefinitionData) {
                $failures[] = "Plan [{$plan->slug}] meter #{$index} is missing a `key` or an `event_name`, so nothing is reported for it.";

                continue;
            }

            if ($meter->meter_id === null) {
                $warnings[] = "Plan [{$plan->slug}] meter [{$meter->event_name}] declares no `meter_id`, so billing:reconcile-usage cannot compare it against Stripe.";
            }
        }
    }

    /** Absent before the first `migrate`, which must not fail this check. */
    private static function tableExists(): bool
    {
        $model = new (Numerosis::model(PaymentPlan::class));

        return Schema::connection($model->getConnectionName())->hasTable($model->getTable());
    }
}
