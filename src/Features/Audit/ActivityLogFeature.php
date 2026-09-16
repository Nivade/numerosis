<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Audit;

use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Features\Concerns\IsNamedFeature;

/**
 * The screens that read the activity log, on by default. Writing entries is
 * unconditional — `activitylog.enabled` is the switch for that, and a log
 * with holes in it answers nothing.
 */
class ActivityLogFeature implements NamedFeature
{
    use IsNamedFeature;

    public const NAME = 'activity_log';
}
