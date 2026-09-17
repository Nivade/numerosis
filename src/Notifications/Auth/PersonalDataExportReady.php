<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Nvade\Numerosis\Models\Central\DataExportRequest;

/**
 * The link is signed, single-use and short-lived: the artefact behind it is
 * everything the package holds about one person.
 */
class PersonalDataExportReady extends Notification
{
    use Queueable;

    public function __construct(public DataExportRequest $request) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
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
