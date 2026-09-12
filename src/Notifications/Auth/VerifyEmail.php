<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Notifications\Auth;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\User;
use Override;

class VerifyEmail extends BaseVerifyEmail
{
    /**
     * The `id`/`hash` pair must match what
     * {@see \Nvade\Numerosis\Http\Requests\Auth\NumerosisVerifyEmailRequest::authorize()}
     * compares against: the global identifier, and `sha1()` of the address.
     * A salted hash never compares equal there, so the link would 403.
     */
    #[Override]
    protected function verificationUrl(mixed $notifiable): string
    {
        if (static::$createUrlCallback !== null) {
            return call_user_func(static::$createUrlCallback, $notifiable);
        }

        throw_if(! $notifiable instanceof User, InvalidArgumentException::class, 'Notifiable must be a '.User::class.'.');

        $originUrl = $this->determineOriginUrl();
        $appUrl = Config::string('app.url');

        try {
            if ($originUrl !== null && $originUrl !== '') {
                URL::useOrigin($originUrl);
            }

            return URL::temporarySignedRoute('verification.verify', Date::now()->addMinutes(Config::integer('numerosis.auth.verification_expire', 60)), [
                'id' => $notifiable->getGlobalIdentifierKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]);
        } finally {
            URL::useOrigin($appUrl);
        }
    }

    /**
     * The tenant's own domain while tenancy is initialized, so the link lands
     * on the host the session was established for.
     */
    protected function determineOriginUrl(): ?string
    {
        if (tenancy()->initialized) {
            /** @var Tenant $tenant */
            $tenant = tenant();
            $domain = $tenant->primaryDomain();

            if ($domain !== null) {
                return Request::getScheme().'://'.$domain->domain;
            }
        }

        if (Request::getHost() !== '') {
            return Request::getScheme().'://'.Request::getHost();
        }

        return Config::string('app.url');
    }
}
