<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Notifications;

use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;
use Nvade\Numerosis\Actions\Notifications\MarkNotificationsRead;
use Nvade\Numerosis\Actions\Queries\GetUserNotifications;
use Nvade\Numerosis\Concerns\Auth\RequiresAuthenticatedUser;
use Nvade\Numerosis\Data\Notifications\NotificationItem;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

/**
 * The bell and its panel. One component on both sides of tenancy: inside a
 * tenant it shows that workspace's news plus anything that belongs to no
 * workspace, and on the central domain it shows everything the person has.
 *
 * Notifications are central rows read from a tenant screen, so the scope is
 * explicit here instead of relying on whichever connection is open.
 */
class Center extends Component
{
    use RequiresAuthenticatedUser;

    public bool $open = false;

    /** Not a Livewire property: a resolved model must not be rehydrated from the payload. */
    private ?CentralUser $resolvedCentralUser = null;

    public function toggle(): void
    {
        $this->open = ! $this->open;

        if ($this->open) {
            $this->markAllRead();
        }
    }

    public function markAllRead(): void
    {
        $user = $this->centralUser();

        if ($user instanceof CentralUser) {
            MarkNotificationsRead::run($user);
        }
    }

    public function markRead(string $id): void
    {
        $user = $this->centralUser();

        if ($user instanceof CentralUser) {
            MarkNotificationsRead::run($user, $id);
        }
    }

    /**
     * Read in `render()` instead of memoized: marking read changes both the
     * list and the count, and a cached computed property would keep showing
     * the badge the click was meant to clear.
     */
    public function render(): View
    {
        $user = $this->centralUser();
        $scope = $this->tenantScope();

        /** @var Collection<int, NotificationItem> $items */
        $items = $user instanceof CentralUser
            ? GetUserNotifications::run($user, $scope)
            : new Collection;

        return view('numerosis::livewire.notifications.center', [
            'items' => $items,
            'unread' => $items->filter(fn (NotificationItem $item): bool => $item->unread)->count(),
        ]);
    }

    /**
     * The tenant guard's user is a tenant row; the notifications belong to the
     * central account behind it, found by the identity the two share.
     */
    private function centralUser(): ?CentralUser
    {
        if ($this->resolvedCentralUser instanceof CentralUser) {
            return $this->resolvedCentralUser;
        }

        $user = $this->authenticatedUser();

        if ($user instanceof CentralUser) {
            return $this->resolvedCentralUser = $user;
        }

        /** @var CentralUser|null $central */
        $central = Numerosis::model(CentralUser::class)::query()
            ->where('global_id', $user->global_id)
            ->first();

        return $this->resolvedCentralUser = $central;
    }

    private function tenantScope(): ?string
    {
        $tenant = tenant();

        return $tenant instanceof Tenant ? (string) $tenant->getTenantKey() : null;
    }
}
