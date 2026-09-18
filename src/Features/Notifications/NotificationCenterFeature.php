<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Notifications;

use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Features\Concerns\IsNamedFeature;

/** The bell in the header, on by default. */
class NotificationCenterFeature implements NamedFeature
{
    use IsNamedFeature;

    public const NAME = 'notification_center';
}
