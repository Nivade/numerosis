<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Auth;

use Illuminate\Auth\Events\Verified;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\UrlGenerator;
use Nvade\Numerosis\Features\Ui\AccountPagesFeature;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Http\Requests\EmailVerificationRequest;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Routes\RouteNames;

class VerifyEmailController extends Controller
{
    public function __construct(private readonly Redirector $redirector, private readonly UrlGenerator $urlGenerator, private readonly Dispatcher $dispatcher) {}

    public function getDefaultRedirectRoute(): RedirectResponse
    {
        return $this->redirector->intended($this->urlGenerator->route($this->defaultRouteName()));
    }

    private function defaultRouteName(): string
    {
        return Features::enabled(AccountPagesFeature::NAME) ? RouteNames::tenantsMine() : RouteNames::home();
    }

    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user || $user->hasVerifiedEmail()) {
            return $this->getDefaultRedirectRoute();
        }

        if ($user->markEmailAsVerified()) {
            $this->dispatcher->dispatch(new Verified($user));
        }

        return $this->redirector->intended($this->urlGenerator->route($this->defaultRouteName(), absolute: false).'?verified=1');
    }
}
