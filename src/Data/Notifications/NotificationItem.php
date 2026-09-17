<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Notifications;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Livewire\Wireable;
use Spatie\LaravelData\Concerns\WireableData;
use Spatie\LaravelData\Data;

/**
 * One in-app notification as the panel shows it: rendered copy, never the raw
 * payload. `tenant_id` is what scopes a list read from inside one workspace.
 */
class NotificationItem extends Data implements Wireable
{
    use WireableData;

    public function __construct(
        public string $id,
        public string $title,
        public ?string $body,
        public ?string $action_url,
        public ?string $tenant_id,
        public ?string $tenant_name,
        public bool $unread,
        public ?string $when,
    ) {}

    public static function fromNotification(DatabaseNotification $notification): self
    {
        $data = $notification->getAttribute('data');
        $data = is_array($data) ? $data : [];

        $string = static function (string $key) use ($data): ?string {
            $value = $data[$key] ?? null;

            return is_string($value) && $value !== '' ? $value : null;
        };

        $id = $notification->getAttribute('id');

        return new self(
            id: is_scalar($id) ? (string) $id : '',
            title: $string('title') ?? 'Notification',
            body: $string('body'),
            action_url: $string('action_url'),
            tenant_id: $string('tenant_id'),
            tenant_name: $string('tenant_name'),
            unread: $notification->getAttribute('read_at') === null,
            when: ($createdAt = $notification->getAttribute('created_at')) instanceof Carbon
                ? $createdAt->diffForHumans()
                : null,
        );
    }
}
