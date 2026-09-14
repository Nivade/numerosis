<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Actions\Billing\Checkout\CompleteRedirectCheckout;
use Nvade\Numerosis\Actions\Billing\Checkout\StartLocalCheckout;
use Nvade\Numerosis\Actions\Billing\Checkout\StartSubscriptionCheckout;
use Nvade\Numerosis\Enums\Auth\SocialProvider;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Features\Auth\PasswordResetFeature;
use Nvade\Numerosis\Features\Auth\SocialLoginFeature;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Features\Invitations\InvitationsFeature;
use Nvade\Numerosis\Features\Tenancy\RegistrationWizardFeature;
use Nvade\Numerosis\Http\Controllers\Auth\Social\DestroySocialAccountController;
use Nvade\Numerosis\Http\Controllers\Auth\Social\HandleProviderCallbackController;
use Nvade\Numerosis\Http\Controllers\Auth\Social\RedirectToProviderController;
use Nvade\Numerosis\Http\Controllers\Billing\WebhookController;
use Nvade\Numerosis\Http\Controllers\Invitations\AcceptInvitationController;
use Nvade\Numerosis\Http\Controllers\Invitations\ShowInvitationController;
use Nvade\Numerosis\Livewire\Settings\ConnectedAccounts;
use Nvade\Numerosis\Livewire\Settings\Password as PasswordSettings;
use Nvade\Numerosis\Livewire\Settings\Profile as ProfileSettings;
use Nvade\Numerosis\Livewire\Tenant\Registration;
use Nvade\Numerosis\Routing\RouteNames;

// The central guard's name is a host-overridable config key, read once here
// instead of spelled `auth:web` at each call site.
$centralAuth = 'auth:'.Context::Central->guard();

// Registered unconditionally: the one central route name everything else
// falls back to. The view is a placeholder a host replaces through
// `numerosis.routes.home_view`.
Route::get('/', fn () => view(Config::string('numerosis.routes.home_view')))
    ->name(RouteNames::home());

// Stripe Webhooks - No auth/CSRF protection needed
Route::post(
    Config::string('numerosis.billing.webhook_path', 'billing/webhook'),
    [WebhookController::class, 'handleWebhook']
)->name('billing.webhook');

// Self-serve tenant registration wizard. Deliberately outside the `auth:web`
// group below — signing up is how a user gets an account in the first place.
if (FeatureRegistry::enabled(RegistrationWizardFeature::NAME)) {
    Route::livewire('/get-started', Registration::class)->name('tenants.create');
}

if (FeatureRegistry::enabled(SocialLoginFeature::NAME)) {
    // An unconfigured provider 404s at routing rather than exploding inside a
    // Socialite driver. Zero providers would build an empty, invalid regex, so
    // an impossible pattern keeps both routes registered but unreachable.
    $configuredProviders = SocialProvider::configuredValues();
    $providerPattern = $configuredProviders === [] ? '(?!)' : implode('|', $configuredProviders);

    Route::middleware(['throttle:social'])->group(function () use ($providerPattern): void {
        Route::get('/auth/{provider}/redirect', RedirectToProviderController::class)
            ->where('provider', $providerPattern)
            ->name(Config::string('numerosis.social.routes.redirect.name'));

        Route::get('/auth/{provider}/callback', HandleProviderCallbackController::class)
            ->where('provider', $providerPattern)
            ->name(Config::string('numerosis.social.routes.callback.name'));
    });
}

if (FeatureRegistry::enabled(InvitationsFeature::NAME)) {
    Route::middleware(['signed', 'throttle:6,1'])->group(function () use ($centralAuth): void {
        Route::get('/invitations/{invitation}', ShowInvitationController::class)
            ->name(RouteNames::invitationShow());

        Route::middleware($centralAuth)->post('/invitations/{invitation}', AcceptInvitationController::class)
            ->name(RouteNames::invitationAccept());
    });
}

Route::middleware([$centralAuth])->group(function () {
    // The account UI ships with core unconditionally; there is no feature
    // flag to toggle it.
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', ProfileSettings::class)->name('settings.profile');

    // The password page has nowhere to send a user who cannot set a password.
    if (FeatureRegistry::enabled(PasswordResetFeature::NAME)) {
        Route::livewire('settings/password', PasswordSettings::class)->name('settings.password');
    }

    if (FeatureRegistry::enabled(SocialLoginFeature::NAME)) {
        Route::livewire('settings/connected-accounts', ConnectedAccounts::class)
            ->name('settings.connected-accounts');

        // Not plain `password.confirm`: a user who registered through OAuth
        // has no password to confirm with, and `SocialAccountPolicy::delete()`
        // is what protects them. See `Http\Middleware\RequirePasswordIfSet`.
        Route::delete('/settings/social/{socialAccount}', DestroySocialAccountController::class)
            ->middleware('password.confirm.if-set')
            ->name('social.destroy');
    }

    // `numerosis-pages::`, core's own Livewire full-page namespace.
    Route::livewire('/tenants/mine', 'numerosis-pages::tenant.mine')->name(RouteNames::tenantsMine());

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

    Route::get('checkout/subscription/new', StartSubscriptionCheckout::class)->name(RouteNames::checkoutSubscription());
    Route::get('/checkout/subscription/return', CompleteRedirectCheckout::class)->name('checkout.subscription.return');
    Route::livewire('/checkout/{domain}', Nvade\Numerosis\Livewire\Billing\Checkout::class)->name('checkout.resume');
    // Provisions a paid resource without payment. The environment guard must
    // stay on the route itself, not merely on the link that reaches it.
    if (app()->isLocal()) {
        Route::get('/checkout/subscription/dev', StartLocalCheckout::class)->name('checkout.subscription.dev');
    }
});

// Fortify owns `login`, `register`, `logout` and the password and
// verification routes, loaded by `Routing\RouteLoader::load()` into this
// domain group and again into the tenant group.
