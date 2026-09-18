<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Notifications\Tenancy;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Nvade\Numerosis\Concerns\Notifications\RespectsPreferences;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * A mail notification about one tenant, delivered to whoever
 * {@see \Nvade\Numerosis\Contracts\Notifications\NotifiesTenantOwner} routes
 * it to. Subclasses supply `toMail()`, their notification type, and the in-app
 * copy.
 */
abstract class TenantNotification extends Notification
{
    use Queueable;
    use RespectsPreferences;

    public function __construct(public Tenant $tenant) {}

    /**
     * The in-app copy, rendered instead of raw: a notification centre listing
     * payload keys is not a feature. Subclasses override it; this is the
     * fallback, built from the mail subject so a new notification is never
     * blank in the panel.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload($this->notificationType()->label(), '');
    }

    /**
     * A signed, single-type unsubscribe link, appended by subclasses whose type
     * may be disabled. Signed because it names the person, and scoped to one
     * type because a link that mutes everything is a griefing vector.
     */
    protected function unsubscribeUrl(object $notifiable): ?string
    {
        $globalId = $notifiable->global_id ?? null;

        if (! $this->notificationType()->mayDisableMail() || ! is_string($globalId)) {
            return null;
        }

        return URL::signedRoute('notifications.unsubscribe', [
            'globalId' => $globalId,
            'type' => $this->notificationType()->value,
        ]);
    }

    protected function unsubscribeLine(object $notifiable): ?string
    {
        $unsubscribe = $this->unsubscribeUrl($notifiable);

        return $unsubscribe === null ? null : "Stop these emails: {$unsubscribe}";
    }

    /**
     * @return array{type: string, title: string, body: string, action_url: string|null, tenant_id: string, tenant_name: string|null}
     */
    protected function payload(string $title, string $body, ?string $actionUrl = null): array
    {
        return [
            'type' => $this->notificationType()->value,
            'title' => $title,
            'body' => $body,
            'action_url' => $actionUrl,
            'tenant_id' => (string) $this->tenant->getTenantKey(),
            'tenant_name' => $this->tenant->name,
        ];
    }
}
