<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Ui;

/**
 * The feature name core and `nvade/numerosis-account` both need, owned here so
 * it cannot drift across the package boundary.
 *
 * The account UI itself — settings, the workspace list, the billing portal —
 * is not core's; it moved out because it is product surface, not framework.
 * But six core and satellite call sites still have to ask whether it is
 * present, because they choose a post-login/post-checkout redirect between
 * `tenants.mine` and `home`:
 *
 * - {@see \Nvade\Numerosis\Actions\Auth\ResolvePostLoginRedirectUrl}
 * - {@see \Nvade\Numerosis\Http\Controllers\Auth\VerifyEmailController}
 * - {@see \Nvade\Numerosis\Livewire\Billing\Checkout}
 * - {@see \Nvade\Numerosis\Services\Billing\Checkout\LocalCheckoutGateway}
 * - `Nvade\NumerosisAuthUi\Livewire\{VerifyEmail,ConfirmPassword}`
 *
 * Reading `AccountPagesFeature::NAME` at those sites would autoload a class
 * that may not be installed — unlike a bare `use` import or a `::class` fetch,
 * a *constant* fetch does trigger autoloading — so an absent optional package
 * would fatal every one of them. The satellite's own `NAME` is defined as
 * `= AccountPages::FEATURE`.
 *
 * Same shape, and same reasoning, as
 * {@see \Nvade\Numerosis\Support\Tenancy\SelfServeRegistration::FEATURE} for
 * `nvade/numerosis-onboarding` and
 * {@see \Nvade\Numerosis\Support\Social\ConfiguredProviders::FEATURE} for
 * `nvade/numerosis-auth-ui`. See `.claude/rules/package-split.md`.
 */
final class AccountPages
{
    public const FEATURE = 'ui.account';
}
