<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Tenancy;

/**
 * What happened to one provisioning step. Recorded rather than inferred, so a
 * step that never ran is distinguishable from one that ran and did nothing.
 */
enum StepOutcome: string
{
    case Done = 'done';

    /**
     * The step declared contributions the provision does not carry — billing
     * steps on a tenant provisioned without billing, say. Recorded with the
     * missing contribution so the absence is visible, not silent.
     */
    case Skipped = 'skipped';

    /**
     * The step threw and exhausted its retries. Recorded so the failing step,
     * its attempts and its error survive the queue, and deliberately not
     * counted as run: a retry of the chain runs this step again.
     */
    case Failed = 'failed';

    public function finishesStep(): bool
    {
        return $this !== self::Failed;
    }
}
