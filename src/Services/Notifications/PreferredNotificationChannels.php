<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Notifications;

use Nvade\Numerosis\Contracts\Notifications\NotificationChannels;
use Nvade\Numerosis\Enums\Notifications\NotificationType;
use Nvade\Numerosis\Models\Central\NotificationPreference;
use Nvade\Numerosis\Numerosis;

/**
 * Defaults from the type, overridden by whatever the recipient stored.
 *
 * Mail for a type the user may not disable is not negotiable here: the override
 * is ignored rather than trusted, so a row written by hand — or by a future
 * screen that forgot the rule — cannot silence a payment failure.
 */
class PreferredNotificationChannels implements NotificationChannels
{
    /** @var array<string, array{mail: bool|null, database: bool|null}> */
    private array $memoized = [];

    /**
     * @return list<string>
     */
    public function for(NotificationType $type, object $notifiable): array
    {
        $override = $this->override($type, $this->globalIdOf($notifiable));

        $mail = $type->mayDisableMail()
            ? ($override['mail'] ?? true)
            : true;

        $database = $override['database'] ?? true;

        $channels = [];

        if ($mail) {
            $channels[] = 'mail';
        }

        // Only a notifiable Laravel can store a row against: a mail-routed
        // address, which is what the operator channel uses, has nowhere to keep
        // one.
        if ($database && method_exists($notifiable, 'notifications')) {
            $channels[] = 'database';
        }

        return $channels;
    }

    /**
     * @return array{mail: bool|null, database: bool|null}
     */
    private function override(NotificationType $type, ?string $globalId): array
    {
        if ($globalId === null) {
            return ['mail' => null, 'database' => null];
        }

        $key = $globalId.'|'.$type->value;

        if (array_key_exists($key, $this->memoized)) {
            return $this->memoized[$key];
        }

        /** @var NotificationPreference|null $preference */
        $preference = Numerosis::model(NotificationPreference::class)::query()
            ->where('global_id', $globalId)
            ->where('type', $type->value)
            ->first();

        return $this->memoized[$key] = [
            'mail' => $preference?->mail,
            'database' => $preference?->database,
        ];
    }

    private function globalIdOf(object $notifiable): ?string
    {
        $globalId = property_exists($notifiable, 'global_id') || method_exists($notifiable, 'getAttribute')
            ? $notifiable->global_id ?? null
            : null;

        return is_string($globalId) ? $globalId : null;
    }
}
