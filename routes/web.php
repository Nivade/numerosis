<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Auth\LogoutUser;
use Nvade\Numerosis\Actions\Billing\Checkout\CompleteRedirectCheckout;
use Nvade\Numerosis\Actions\Billing\Checkout\StartLocalCheckout;
use Nvade\Numerosis\Actions\Billing\Checkout\StartSubscriptionCheckout;
use Nvade\Numerosis\Http\Controllers\Auth\VerifyEmailController;
use Nvade\Numerosis\Http\Controllers\Billing\WebhookController;
use Nvade\Numerosis\Support\Numerosis;
use Nvade\Numerosis\Support\Routes\RouteNames;

// 'home' is registered unconditionally, behind no feature flag.
// Socialite\Login::tenantDashboardUrl() builds an OAuth tenant-redirect URL by
// swapping this route's host (see its own docblock), CompleteRedirectCheckout
// falls back to it on a checkout error, and the tenant panel documents 'home'
// as the one route name guaranteed to exist on the central domain regardless
// of panel registration. All three depend on this staying unconditional.
//
// The view it renders is a deliberate placeholder. Marketing pages — a
// homepage worth showing, plus terms/privacy/about/features — are the
// *product's*, not the framework's, and live in the host app; core shipping
// them was what made "does this belong in core?" unanswerable.
//
// Point `numerosis.routes.home_view` at your own view to replace the page.
// Registering a second route named `home` does not work: this one is declared
// first, so it wins the path match.
Route::get('/', fn () => view(Config::string('numerosis.routes.home_view')))
    ->name(RouteNames::home());

// Stripe Webhooks - No auth/CSRF protection needed
Route::post(
    uri: config(key: 'numerosis.billing.webhook_path', default: 'billing/webhook'),
    action: [WebhookController::class, 'handleWebhook']
)->name('billing.webhook');

Route::middleware(['auth:web'])->group(function () {
    // The account UI (`settings/*`, `tenants.mine`, invoice downloads, the
    // billing portal) is contributed by nvade/numerosis-account through
    // Numerosis::addCentralRoutes(), so it lands in this same central-domain
    // group without core naming that package. Core still *links* to
    // `tenants.mine`, gated on Support\Ui\AccountPages::FEATURE.

    // `/get-started` (route name `tenants.create`) is contributed by
    // nvade/numerosis-onboarding through Numerosis::addCentralRoutes(), so it
    // lands in this same central-domain group without core naming that
    // package. Core still *links* to the name from four views and from
    // CompleteRedirectCheckout, each gated on
    // Support\Tenancy\SelfServeRegistration::FEATURE.

    Route::get('checkout/subscription/new', StartSubscriptionCheckout::class)->name('checkout.subscription');
    Route::get('/checkout/subscription/return', CompleteRedirectCheckout::class)->name('checkout.subscription.return');
    Route::livewire('/checkout/{domain}', Nvade\Numerosis\Livewire\Billing\Checkout::class)->name('checkout.resume');
    // Provisions a paid resource without payment. The environment guard must
    // stay on the route itself, not merely on the link that reaches it.
    if (app()->isLocal()) {
        Route::get('/checkout/subscription/dev', StartLocalCheckout::class)->name('checkout.subscription.dev');
    }

});

// The auth screens (`login`, `register`, `forgot-password`, `reset-password`,
// the OAuth redirect/callback) live in `nvade/numerosis-auth-ui`, which
// contributes them through `Numerosis::addCentralRoutes()` — so they still
// land inside this file's own per-central-domain group. That package honours
// `Numerosis::authRoutesEnabled()` itself, which is why the flag stays public
// here even though nothing in this file reads it any more.
//
// `logout` and `verification.verify` are the exception: their handlers
// (`Actions\Auth\LogoutUser`, `Http\Controllers\Auth\VerifyEmailController`)
// are core auth *mechanics*, not screens, and core's own flows generate both
// names — so they stay here and remain subject to the same opt-out flag.
if (Numerosis::authRoutesEnabled()) {
    Route::post('logout', LogoutUser::class)->name('logout');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1', 'auth'])
        ->name('verification.verify');
}
