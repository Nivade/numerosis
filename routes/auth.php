<?php

declare(strict_types=1);

use Nvade\Numerosis\Features\Auth\PasswordResetFeature;
use Nvade\Numerosis\Features\Social\SocialLoginFeature;
use Nvade\Numerosis\Http\Controllers\Socialite as Social;
use Nvade\Numerosis\Livewire\Auth\ForgotPassword;
use Nvade\Numerosis\Livewire\Auth\PasswordlessLogin;
use Nvade\Numerosis\Livewire\Auth\Register;
use Nvade\Numerosis\Livewire\Auth\ResetPassword;
use Nvade\Numerosis\Support\Features;
use Illuminate\Support\Facades\Route;

if (Features::enabled(SocialLoginFeature::NAME)) {
    Route::get('/oauth/{driver}/callback', Social\Login::class)
        ->name('oauth.callback');

    Route::get('/oauth/{driver}', Social\Redirect::class)
        ->domain(config('app.central.default'))
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

Route::post('logout', Nvade\Numerosis\Actions\Auth\LogoutUser::class)
    ->name('logout');

Route::get('verify-email/{id}/{hash}', Nvade\Numerosis\Http\Controllers\Auth\VerifyEmailController::class)
    ->middleware(['signed', 'throttle:6,1', 'auth'])
    ->name('verification.verify');
