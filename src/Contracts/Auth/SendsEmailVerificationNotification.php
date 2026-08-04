<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Auth;

use Illuminate\Contracts\Auth\MustVerifyEmail;

/**
 * Sends the email verification notification for a user. Bind a replacement
 * in a service provider to change the notification (copy, channel) without
 * overriding {@see \Nvade\Numerosis\Models\User::sendEmailVerificationNotification()}
 * on the shared abstract user model.
 */
interface SendsEmailVerificationNotification
{
    public function send(MustVerifyEmail $notifiable): void;
}
