<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Notifications;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nvade\Numerosis\Actions\Notifications\SaveNotificationPreference;
use Nvade\Numerosis\Enums\Notifications\NotificationType;
use Nvade\Numerosis\Http\Controllers\Controller;

/**
 * The link is signed and turns off exactly one type. A link that switched
 * everything off would let anyone who receives a forwarded mail silence the
 * whole mailbox.
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
                database: true,
            );
        }

        return response()->view('numerosis::notifications.unsubscribed', [
            'type' => $notificationType,
        ]);
    }
}
