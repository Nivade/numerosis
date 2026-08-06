<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Nvade\Numerosis\Actions\Billing\Checkout\CompleteRedirectCheckout;
use Nvade\Numerosis\Actions\Billing\Checkout\StartLocalCheckout;
use Nvade\Numerosis\Actions\Billing\Checkout\StartSubscriptionCheckout;
use Nvade\Numerosis\Features\Auth\PasswordResetFeature;
use Nvade\Numerosis\Features\Tenancy\RegistrationWizardFeature;
use Nvade\Numerosis\Features\Ui\AccountPagesFeature;
use Nvade\Numerosis\Features\Ui\MarketingPagesFeature;
use Nvade\Numerosis\Http\Controllers\Billing\WebhookController;
use Nvade\Numerosis\Livewire\Settings\Appearance;
use Nvade\Numerosis\Livewire\Settings\Password;
use Nvade\Numerosis\Livewire\Settings\Profile;
use Nvade\Numerosis\Livewire\Tenant;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Routes\RouteNames;

//

// 'home' is deliberately NOT gated behind MarketingPagesFeature, unlike the
// other four marketing routes below. Socialite\Login::tenantDashboardUrl()
// builds an OAuth tenant-redirect URL by swapping this route's host (see its
// own docblock), CompleteRedirectCheckout falls back to it on a checkout
// error, and TenantAdminPanelProvider::register() documents 'home' as the
// one route name guaranteed to exist on the central domain regardless of
// panel registration. All three depend on this staying unconditional.
Route::get('/', function () {
    return view('numerosis::welcome')->layout('numerosis::layouts.app');
})->name(RouteNames::home());

if (Features::enabled(MarketingPagesFeature::NAME)) {
    Route::view('terms', 'numerosis::terms')->name('terms');
    Route::view('privacy', 'numerosis::privacy')->name('privacy');
    Route::view('about', 'numerosis::about')->name('about');
    Route::view('features', 'numerosis::features')->name('features');
}

// Stripe Webhooks - No auth/CSRF protection needed
Route::post(
    uri: config(key: 'numerosis.billing.webhook_path', default: 'billing/webhook'),
    action: [WebhookController::class, 'handleWebhook']
)->name('billing.webhook');

Route::middleware(['auth:web'])->group(function () {
    if (Features::enabled(AccountPagesFeature::NAME)) {
        Route::redirect('settings', 'settings/profile');

        Route::livewire('settings/profile', Profile::class)->name('settings.profile');

        if (Features::enabled(PasswordResetFeature::NAME)) {
            Route::livewire('settings/password', Password::class)->name('settings.password');
        }

        Route::livewire('settings/appearance', Appearance::class)->name('settings.appearance');

        Route::livewire('/tenants/mine', 'numerosis::pages.tenant.mine')->name(RouteNames::tenantsMine());
    }

    if (Features::enabled(RegistrationWizardFeature::NAME)) {
        Route::livewire('/get-started', Tenant\Registration\Registration::class)->name('tenants.create');
    }
    //    Route::view('/registration-success', 'tenant.registration-success')->name('tenant.registration.success');

    Route::get('checkout/subscription/new', StartSubscriptionCheckout::class)->name('checkout.subscription');
    Route::get('/checkout/subscription/return', CompleteRedirectCheckout::class)->name('checkout.subscription.return');
    Route::livewire('/checkout/{domain}', Nvade\Numerosis\Livewire\Billing\Checkout::class)->name('checkout.resume');
    // Provisions a paid resource without payment. The environment guard must
    // stay on the route itself, not merely on the link that reaches it.
    if (app()->isLocal()) {
        Route::get('/checkout/subscription/dev', StartLocalCheckout::class)->name('checkout.subscription.dev');
    }

    if (Features::enabled(AccountPagesFeature::NAME)) {
        Route::get('/user/invoice/{invoice}', function (Request $request, string $invoiceId) {
            return $request->user()->downloadInvoice($invoiceId);
        });
        Route::get('/billing-portal', function (Request $request) {
            return $request->user()->redirectToBillingPortal();
        })->name('billing-portal');
    }

});

require __DIR__.'/auth.php';
