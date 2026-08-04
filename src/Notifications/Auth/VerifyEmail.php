<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Notifications\Auth;

use Nvade\Numerosis\Models\Central\Tenant;
use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

class VerifyEmail extends BaseVerifyEmail
{
    /**
     * Get the verification URL for the given notifiable.
     */
    protected function verificationUrl(mixed $notifiable): string
    {
        if (static::$createUrlCallback) {
            return call_user_func(static::$createUrlCallback, $notifiable);
        }

        throw_if(! $notifiable instanceof Model || ! $notifiable instanceof MustVerifyEmail, InvalidArgumentException::class, 'Notifiable must be a Model implementing MustVerifyEmail.');

        // Determine the correct origin URL based on tenant context
        $originUrl = $this->determineOriginUrl();

        // For multi-tenancy: temporarily override the root URL to match the tenant domain
        $previousRootUrl = Config::string('app.url');

        try {
            if ($originUrl) {
                URL::useOrigin($originUrl);
            }

            return URL::temporarySignedRoute('verification.verify', Date::now()->addMinutes(Config::integer('auth.verification.expire', 60)), [
                'id' => $notifiable->getKey(),
                'hash' => Hash::make($notifiable->getEmailForVerification()),
            ]);
        } finally {
            // Restore original root URL
            URL::useOrigin($previousRootUrl);
        }
    }

    /**
     * Determine the origin URL for the verification link.
     */
    protected function determineOriginUrl(): ?string
    {
        // If tenancy is initialized, use the tenant's domain
        if (tenancy()->initialized) {
            /** @var Tenant $tenant */
            $tenant = tenant();
            $domain = $tenant->primaryDomain();

            if ($domain) {
                return Request::getScheme().'://'.$domain->domain;
            }
        }

        // Fall back to request URL if available
        if (Request::getHost()) {
            return Request::getScheme().'://'.Request::getHost();
        }

        // Finally, fall back to app URL
        return Config::string('app.url');
    }
}
