<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Notifications\NotificationItem;
use Nvade\Numerosis\Models\Central\CentralUser;

/**
 * The caller's in-app notifications, newest first.
 *
 * Optionally narrowed to one tenant: a person in three workspaces reading from
 * inside one of them must not be shown the other two's news, which is the
 * central-row-on-a-tenant-screen trap in notification form. A notification
 * carrying no tenant is theirs everywhere.
 *
 * @method static Collection<int, NotificationItem> run(CentralUser $user, ?string $tenantId = null, int $limit = 20)
 */
class GetUserNotifications
{
    use AsAction;

    /**
     * @return Collection<int, NotificationItem>
     */
    public function handle(CentralUser $user, ?string $tenantId = null, int $limit = 20): Collection
    {
        /** @var Collection<int, DatabaseNotification> $rows */
        $rows = new Collection($user->notifications()->latest()->limit(max(1, $limit))->get()->all());

        return $rows
            ->map(fn (DatabaseNotification $notification): NotificationItem => NotificationItem::fromNotification($notification))
            ->filter(fn (NotificationItem $item): bool => $tenantId === null
                || $item->tenant_id === null
                || $item->tenant_id === $tenantId)
            ->values();
    }

    /** Unread count for the bell, which every authenticated page renders. */
    public static function unreadCount(CentralUser $user, ?string $tenantId = null): int
    {
        if ($tenantId === null) {
            return $user->unreadNotifications()->count();
        }

        // Unread rows only, and no NotificationItem per row: this runs for a
        // count, not for copy.
        return $user->unreadNotifications()->get()
            ->filter(function (DatabaseNotification $notification) use ($tenantId): bool {
                $data = $notification->getAttribute('data');
                $scope = is_array($data) ? ($data['tenant_id'] ?? null) : null;

                return ! is_string($scope) || $scope === $tenantId;
            })
            ->count();
    }
}
