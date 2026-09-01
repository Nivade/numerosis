<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Features\Auth\PasswordResetFeature;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Routes\RouteNames;
use Nvade\NumerosisAccount\Livewire\Settings\Appearance;
use Nvade\NumerosisAccount\Livewire\Settings\Password;
use Nvade\NumerosisAccount\Livewire\Settings\Profile;

/*
|--------------------------------------------------------------------------
| Account routes
|--------------------------------------------------------------------------
|
| Contributed into core's per-central-domain group through
| `Numerosis::addCentralRoutes()`, so these land on exactly the hostnames
| core's own central routes do. The feature check happens in the provider,
| around the whole contribution, rather than per route.
|
*/

Route::middleware(['auth:web'])->group(function (): void {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', Profile::class)->name('settings.profile');

    // The password page has nowhere to send a user who cannot set a password.
    if (Features::enabled(PasswordResetFeature::NAME)) {
        Route::livewire('settings/password', Password::class)->name('settings.password');
    }

    Route::livewire('settings/appearance', Appearance::class)->name('settings.appearance');

    // `account-pages::`, this package's own Livewire view namespace — core's
    // `pages::` maps to a single directory it owns, so a satellite cannot
    // append to it the way it can with the `numerosis::` *view* namespace.
    Route::livewire('/tenants/mine', 'account-pages::tenant.mine')
        ->name(RouteNames::tenantsMine());

    // `auth:web` above guarantees a user; the guards make that legible to
    // static analysis rather than asserting it.
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
});
