<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Actions\Queries\GetApiAbilities;
use Nvade\Numerosis\Enums\Auth\PermissionAction;
use Nvade\Numerosis\Enums\Auth\PermissionContext;
use Nvade\Numerosis\Enums\MiddlewareAlias;
use Nvade\Numerosis\Http\Controllers\Api\V1\InvitationController;
use Nvade\Numerosis\Http\Controllers\Api\V1\MemberController;
use Nvade\Numerosis\Http\Controllers\Api\V1\SubscriptionController;
use Nvade\Numerosis\Http\Controllers\Api\V1\TenantController;

/*
 * The package's read API. Loaded by RouteLoader inside the `api` prefix, under
 * the `tenant-api` group: tenancy identification without a session, since the
 * token carries the caller.
 *
 * Read-only on purpose. Every write endpoint needs the same authorization
 * reasoning as the screen that already does that job, and an API that writes
 * before that reasoning exists is the wrong kind of stable.
 */
Route::middleware([
    'tenant-api',
    'auth:sanctum',
    MiddlewareAlias::ApiToken->value,
    'throttle:numerosis-api',
])->prefix('v1')->name('numerosis.api.v1.')->group(function (): void {
    Route::get('tenant', TenantController::class)
        ->middleware(MiddlewareAlias::ApiAbilities->value.':'.GetApiAbilities::ability(PermissionContext::Tenants, PermissionAction::View))
        ->name('tenant');

    Route::get('members', MemberController::class)
        ->middleware(MiddlewareAlias::ApiAbilities->value.':'.GetApiAbilities::ability(PermissionContext::Users, PermissionAction::ViewAny))
        ->name('members');

    Route::get('invitations', InvitationController::class)
        ->middleware(MiddlewareAlias::ApiAbilities->value.':'.GetApiAbilities::ability(PermissionContext::Invitations, PermissionAction::ViewAny))
        ->name('invitations');

    Route::get('subscription', SubscriptionController::class)
        ->middleware(MiddlewareAlias::ApiAbilities->value.':'.GetApiAbilities::ability(PermissionContext::Subscriptions, PermissionAction::View))
        ->name('subscription');
});
