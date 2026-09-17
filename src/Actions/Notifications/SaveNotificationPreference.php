<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Notifications;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Notifications\NotificationType;
use Nvade\Numerosis\Models\Central\NotificationPreference;
use Nvade\Numerosis\Numerosis;

/**
 * One override row per person per type. Mail for a type the type itself says may
 * not be disabled is stored as on, whatever was asked for — the rule lives on
 * the enum, so no screen or link can route around it.
 *
 * @method static NotificationPreference run(string $globalId, NotificationType $type, bool $mail, bool $database)
 */
class SaveNotificationPreference
{
    use AsAction;

    public function handle(string $globalId, NotificationType $type, bool $mail, bool $database): NotificationPreference
    {
        /** @var NotificationPreference $preference */
        $preference = Numerosis::model(NotificationPreference::class)::query()->updateOrCreate(
            ['global_id' => $globalId, 'type' => $type->value],
            [
                'mail' => $type->mayDisableMail() ? $mail : true,
                'database' => $database,
            ],
        );

        return $preference;
    }
}
