<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Tenancy;

/**
 * The two strings core and `nvade/numerosis-onboarding` both need, owned here
 * so they cannot drift across the package boundary.
 *
 * Neither is core's *behaviour* — core ships no signup wizard — but both are
 * names core has to know:
 *
 * - {@see self::FEATURE} gates six core surfaces that link to the wizard
 *   (`welcome`, `features`, `components/footer`, `pages/tenant/⚡mine`, and
 *   `Actions\Billing\Checkout\CompleteRedirectCheckout`'s post-checkout
 *   redirect). Reading `RegistrationWizardFeature::NAME` at those sites would
 *   autoload a class that may not be installed — unlike a bare `use` import
 *   or a `::class` fetch, a *constant* fetch does trigger autoloading — so an
 *   absent optional package would fatal five core screens. The satellite's
 *   own `NAME` is defined as `= SelfServeRegistration::FEATURE`.
 * - {@see self::SESSION_KEY} is written and read by the wizard, and cleared
 *   by core's two checkout completion paths
 *   (`CompleteRedirectCheckout`, `Livewire\Billing\Checkout::settle()`).
 *   It was a duplicated literal in three files before the split; across a
 *   package boundary a typo in one of them would be silent.
 *
 * Same shape, and same reasoning, as {@see \Nvade\Numerosis\Support\Social\ConfiguredProviders::FEATURE}
 * for `nvade/numerosis-auth-ui`. See `.claude/rules/package-split.md`.
 */
final class SelfServeRegistration
{
    public const FEATURE = 'registration_wizard';

    public const SESSION_KEY = 'registration.wizard_state';
}
