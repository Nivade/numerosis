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
 * Notifications are central rows read from a tenant screen, which is the trap
 * `.ai/rules/central-rows-on-tenant-routes.md` names — so the scope is explicit
 * here rather than left to whatever connection is open.
 */
class Center extends Component
{
    use RequiresAuthenticatedUser;

    public bool $open = false;

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
     * Read in `render()` rather than memoized: marking read changes both the
     * list and the count, and a cached computed property would keep showing the
     * badge the click was meant to clear.
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
        $user = $this->authenticatedUser();

        if ($user instanceof CentralUser) {
            return $user;
        }

        /** @var CentralUser|null $central */
        $central = Numerosis::model(CentralUser::class)::query()
            ->where('global_id', $user->global_id)
            ->first();

        return $central;
    }

    private function tenantScope(): ?string
    {
        $tenant = tenant();

        return $tenant instanceof Tenant ? (string) $tenant->getTenantKey() : null;
    }
}
