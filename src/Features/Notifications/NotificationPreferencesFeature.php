<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Notifications;

use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Features\Concerns\IsNamedFeature;

/** The `settings/notifications` channel matrix, on by default. */
class NotificationPreferencesFeature implements NamedFeature
{
    use IsNamedFeature;

    public const NAME = 'notification_preferences';
}
