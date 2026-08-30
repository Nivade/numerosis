<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Features\Auth\PasswordResetFeature;
use Nvade\NumerosisAuthUi\Features\SocialLoginFeature;
use Nvade\NumerosisAuthUi\Http\Controllers\Socialite as Social;
use Nvade\NumerosisAuthUi\Livewire\ForgotPassword;
use Nvade\NumerosisAuthUi\Livewire\PasswordlessLogin;
use Nvade\NumerosisAuthUi\Livewire\Register;
use Nvade\NumerosisAuthUi\Livewire\ResetPassword;
use Nvade\Numerosis\Support\Features;

if (Features::enabled(SocialLoginFeature::NAME)) {
    Route::get('/oauth/{driver}/callback', Social\Login::class)
        ->name('oauth.callback');

    Route::get('/oauth/{driver}', Social\Redirect::class)
        ->domain(config('numerosis.domains.central'))
        ->name('oauth');
}

Route::middleware('guest')->group(function () {
    Route::get('login', PasswordlessLogin::class)->name('login');
    Route::get('register', Register::class)->name('register');

    if (Features::enabled(PasswordResetFeature::NAME)) {
        Route::get('forgot-password', ForgotPassword::class)->name('password.request');
        Route::get('reset-password/{token}', ResetPassword::class)->name('password.reset');
    }
});
