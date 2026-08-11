<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Features\Invitations\InvitationsFeature;
use Nvade\Numerosis\Features\Tenancy\ImpersonationFeature;
use Nvade\Numerosis\Livewire\Auth\ConfirmPassword;
use Nvade\Numerosis\Livewire\Auth\VerifyEmail;
use Nvade\Numerosis\Livewire\Invitations\Accept;
use Nvade\Numerosis\Support\Features;
use Stancl\Tenancy\Features\UserImpersonation;

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

// Deliberately outside any auth-required group: this route establishes the
// session, it can't require one first. UserImpersonation::makeResponse()
// does its own ownership + TTL check against the token.
if (Features::enabled(ImpersonationFeature::NAME)) {
    Route::get('impersonate/{token}', fn (string $token) => UserImpersonation::makeResponse($token))
        ->name('impersonate');
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
