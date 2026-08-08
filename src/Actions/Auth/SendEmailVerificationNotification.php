<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Auth\SendsEmailVerificationNotification;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Notifications\Auth\VerifyEmail;
use RuntimeException;

/**
 * @method static void run(MustVerifyEmail $notifiable)
 */
class SendEmailVerificationNotification implements SendsEmailVerificationNotification
{
    use AsAction;

    public function handle(MustVerifyEmail $notifiable): void
    {
        $this->send($notifiable);
    }

    public function send(MustVerifyEmail $notifiable): void
    {
        throw_unless($notifiable instanceof User, RuntimeException::class, 'Expected an Nvade\Numerosis\Models\User instance.');

        $notifiable->notify(new VerifyEmail);
    }
}
