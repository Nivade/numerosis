<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Notifications;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nvade\Numerosis\Actions\Notifications\SaveNotificationPreference;
use Nvade\Numerosis\Enums\Notifications\NotificationType;
use Nvade\Numerosis\Http\Controllers\Controller;

/**
 * One click, no login, exactly one type. Signed, because the link identifies the
 * person, and single-purpose, because a link that switches everything off is a
 * griefing vector — anyone who forwards a mail would be handing over the whole
 * mailbox.
 *
 * A type whose mail may not be disabled answers without changing anything: the
 * recipient learns the link arrived, and the package does not pretend it can
 * mute a payment failure.
 */
class UnsubscribeController extends Controller
{
    public function __invoke(Request $request, string $globalId, string $type): Response
    {
        $notificationType = NotificationType::tryFrom($type);

        abort_if($notificationType === null, 404);

        if ($notificationType->mayDisableMail()) {
            SaveNotificationPreference::run(
                $globalId,
                $notificationType,
                mail: false,
                database: $notificationType->databaseByDefault(),
            );
        }

        return response()->view('numerosis::notifications.unsubscribed', [
            'type' => $notificationType,
        ]);
    }
}
