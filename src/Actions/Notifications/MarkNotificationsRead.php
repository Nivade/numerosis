<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Notifications;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\CentralUser;

/**
 * Marks the caller's own notifications read, scoped to the notifiable: an id
 * arriving from a request body must not be able to read somebody else's bell.
 *
 * @method static int run(CentralUser $user, string|null $notificationId = null)
 */
class MarkNotificationsRead
{
    use AsAction;

    /** @return int How many rows were marked. */
    public function handle(CentralUser $user, ?string $notificationId = null): int
    {
        $query = $user->unreadNotifications();

        if ($notificationId !== null) {
            $query->whereKey($notificationId);
        }

        return $query->update(['read_at' => now()]);
    }
}
