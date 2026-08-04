<?php

declare(strict_types=1);

use Nvade\Numerosis\Features\Invitations\InvitationsFeature;
use Nvade\Numerosis\Livewire\Auth\ConfirmPassword;
use Nvade\Numerosis\Livewire\Auth\VerifyEmail;
use Nvade\Numerosis\Livewire\Invitations\Accept;
use Nvade\Numerosis\Support\Features;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider.
|
| Feel free to customize them however you want. Good luck!
|
*/

// Invitation routes (Livewire)
if (Features::enabled(InvitationsFeature::NAME)) {
    Route::get('invitation/{token}', Accept::class)
        ->name('invitation.show')
        ->middleware('invitation.status');
}

Route::middleware(['universal', 'auth:tenant'])->group(function () {
    Route::get('verify-email', VerifyEmail::class)
        ->name('verification.notice');

    Route::get('confirm-password', ConfirmPassword::class)
        ->name('password.confirm');

    // Deliberately outside the tenant Filament panel: EnsureTenantSubscriptionActive
    // is scoped to the panel's own middleware stack, so redirecting here can
    // never loop back through the same gate.
    Route::livewire('account-suspended', 'pages::tenant.suspended')
        ->name('tenant.suspended');
});
