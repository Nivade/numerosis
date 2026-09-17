<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Enums\Auth\PermissionAction;
use Nvade\Numerosis\Enums\Auth\PermissionContext;
use Nvade\Numerosis\Enums\MiddlewareAlias;
use Nvade\Numerosis\Features\Api\ReadApiFeature;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Http\Controllers\Api\V1\DomainController;
use Nvade\Numerosis\Http\Controllers\Api\V1\InvitationController;
use Nvade\Numerosis\Http\Controllers\Api\V1\MemberController;
use Nvade\Numerosis\Http\Controllers\Api\V1\SubscriptionController;
use Nvade\Numerosis\Http\Controllers\Api\V1\TenantController;

if (! FeatureRegistry::enabled(ReadApiFeature::NAME)) {
    return;
}

/** Read-only. A write endpoint needs the authorization reasoning the equivalent screen already has. */
Route::middleware([
    'tenant-api',
    'auth:sanctum',
    MiddlewareAlias::ApiToken->value,
    'throttle:numerosis-api',
])->prefix('v1')->name('numerosis.api.v1.')->group(function (): void {
    Route::get('tenant', TenantController::class)
        ->middleware(MiddlewareAlias::ApiAbilities->value.':'.PermissionContext::Tenants->abilityFor(PermissionAction::View))
        ->name('tenant');

    Route::get('members', MemberController::class)
        ->middleware(MiddlewareAlias::ApiAbilities->value.':'.PermissionContext::Users->abilityFor(PermissionAction::ViewAny))
        ->name('members');

    Route::get('invitations', InvitationController::class)
        ->middleware(MiddlewareAlias::ApiAbilities->value.':'.PermissionContext::Invitations->abilityFor(PermissionAction::ViewAny))
        ->name('invitations');

    Route::get('subscription', SubscriptionController::class)
        ->middleware(MiddlewareAlias::ApiAbilities->value.':'.PermissionContext::Subscriptions->abilityFor(PermissionAction::View))
        ->name('subscription');

    Route::get('domains', DomainController::class)
        ->middleware(MiddlewareAlias::ApiAbilities->value.':'.PermissionContext::Domains->abilityFor(PermissionAction::ViewAny))
        ->name('domains');
});
