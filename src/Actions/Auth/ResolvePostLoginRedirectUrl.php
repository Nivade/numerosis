<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Nvade\Numerosis\Contracts\Auth\ResolvesPostLoginRedirectUrl;
use Nvade\Numerosis\Features\Ui\AccountPagesFeature;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Routes\RouteNames;
use Illuminate\Support\Facades\Session;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static string run()
 */
class ResolvePostLoginRedirectUrl implements ResolvesPostLoginRedirectUrl
{
    use AsAction;

    public function handle(): string
    {
        return $this->url();
    }

    public function url(): string
    {
        $default = match (true) {
            tenancy()->initialized => '/',
            Features::enabled(AccountPagesFeature::NAME) => route(RouteNames::tenantsMine()),
            default => route(RouteNames::home()),
        };

        return $this->intendedUrlForCurrentHost() ?? $default;
    }

    /**
     * `url.intended` lives in a session shared across every tenant subdomain
     * and the central domain alike ({@see SESSION_DOMAIN}), so a value
     * stashed while redirecting from an unrelated host is still there when
     * this runs. Only trust it when it actually points at the host being
     * logged into; otherwise it silently bounces the user to whatever domain
     * last stored one.
     */
    protected function intendedUrlForCurrentHost(): ?string
    {
        $intended = Session::get('url.intended');

        if (! is_string($intended)) {
            return null;
        }

        return parse_url($intended, PHP_URL_HOST) === request()->getHost() ? $intended : null;
    }
}
