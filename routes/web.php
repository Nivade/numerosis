<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Billing\Checkout\CompleteRedirectCheckout;
use Nvade\Numerosis\Actions\Billing\Checkout\StartLocalCheckout;
use Nvade\Numerosis\Actions\Billing\Checkout\StartSubscriptionCheckout;
use Nvade\Numerosis\Features\Auth\PasswordResetFeature;
use Nvade\Numerosis\Features\Auth\SocialLoginFeature;
use Nvade\Numerosis\Features\Tenancy\RegistrationWizardFeature;
use Nvade\Numerosis\Http\Controllers\Billing\WebhookController;
use Nvade\Numerosis\Http\Controllers\Socialite\Login as SocialiteLogin;
use Nvade\Numerosis\Http\Controllers\Socialite\Redirect as SocialiteRedirect;
use Nvade\Numerosis\Livewire\Settings\Password as PasswordSettings;
use Nvade\Numerosis\Livewire\Settings\Profile as ProfileSettings;
use Nvade\Numerosis\Livewire\Tenant\Registration;
use Nvade\Numerosis\Support\Features;
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

// Self-serve tenant registration wizard. Deliberately outside the `auth:web`
// group below — signing up is how a user gets an account in the first place.
if (Features::enabled(RegistrationWizardFeature::NAME)) {
    Route::livewire('/get-started', Registration::class)->name('tenants.create');
}

if (Features::enabled(SocialLoginFeature::NAME)) {
    Route::get('/oauth/{driver}/callback', SocialiteLogin::class)
        ->name('oauth.callback');

    Route::get('/oauth/{driver}', SocialiteRedirect::class)
        ->domain(Config::string('numerosis.domains.central'))
        ->name('oauth');
}

Route::middleware(['auth:web'])->group(function () {
    // The account UI: settings, the workspace list, invoice downloads and
    // the billing portal. Formerly nvade/numerosis-account, contributed
    // through Numerosis::addCentralRoutes() — folded into core in Phase 3 of
    // `.claude/plans/humming-nibbling-flame.md`. No feature flag any more:
    // it always ships with core now, so there is nothing left to toggle.
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', ProfileSettings::class)->name('settings.profile');

    // The password page has nowhere to send a user who cannot set a password.
    if (Features::enabled(PasswordResetFeature::NAME)) {
        Route::livewire('settings/password', PasswordSettings::class)->name('settings.password');
    }

    // `pages::`, core's own Livewire full-page namespace.
    Route::livewire('/tenants/mine', 'pages::tenant.mine')->name(RouteNames::tenantsMine());

    Route::get('/user/invoice/{invoice}', function (Request $request, string $invoiceId) {
        $user = $request->user();
        abort_if($user === null, 403);

        return $user->downloadInvoice($invoiceId);
    });

    Route::get('/billing-portal', function (Request $request) {
        $user = $request->user();
        abort_if($user === null, 403);

        return $user->redirectToBillingPortal();
    })->name('billing-portal');

    Route::get('checkout/subscription/new', StartSubscriptionCheckout::class)->name('checkout.subscription');
    Route::get('/checkout/subscription/return', CompleteRedirectCheckout::class)->name('checkout.subscription.return');
    Route::livewire('/checkout/{domain}', Nvade\Numerosis\Livewire\Billing\Checkout::class)->name('checkout.resume');
    // Provisions a paid resource without payment. The environment guard must
    // stay on the route itself, not merely on the link that reaches it.
    if (app()->isLocal()) {
        Route::get('/checkout/subscription/dev', StartLocalCheckout::class)->name('checkout.subscription.dev');
    }

});

// `login`, `register`, `logout`, `password.request`, `password.reset` and
// `verification.verify` are Laravel Fortify's, loaded by
// `Support\Numerosis::routes()` inside this same domain group (and again
// inside the tenant group) — see `.claude/plans/humming-nibbling-flame.md`
// Phase 4a. `Numerosis::authRoutesEnabled()` (the `withAuth` flag on
// `Numerosis::routes()`) gates that load the same way it used to gate the
// routes declared here directly.
