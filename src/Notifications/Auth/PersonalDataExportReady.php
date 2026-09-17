<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Nvade\Numerosis\Concerns\Notifications\RespectsPreferences;
use Nvade\Numerosis\Enums\Notifications\NotificationType;
use Nvade\Numerosis\Models\Central\DataExportRequest;

/**
 * The link is signed, single-use and short-lived: the artefact behind it is
 * everything the package holds about one person.
 */
class PersonalDataExportReady extends Notification
{
    use Queueable;
    use RespectsPreferences;

    public function __construct(public DataExportRequest $request) {}

    public function notificationType(): NotificationType
    {
        return NotificationType::DataExportReady;
    }

    /**
     * No download link in the panel: the signed one expires with the artefact,
     * and a dead link read back days later is worse than none.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => NotificationType::DataExportReady->value,
            'title' => NotificationType::DataExportReady->label(),
            'body' => __('The copy of your data you asked for has been prepared.'),
            'action_url' => null,
            'tenant_id' => $this->request->tenant_id,
            'tenant_name' => null,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'privacy.exports.download',
            $this->request->expires_at ?? now()->addHour(),
            ['export' => $this->request->getRouteKey()],
        );

        return (new MailMessage)
            ->subject(__('Your data is ready to download'))
            ->line(__('The copy of your data you asked for has been prepared.'))
            ->action(__('Download it'), $url)
            ->line(__('The link works once and expires :when.', [
                'when' => $this->request->expires_at?->diffForHumans() ?? __('shortly'),
            ]));
    }
}
