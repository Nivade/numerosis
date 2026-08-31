<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Support\Features;
use Nvade\NumerosisOnboarding\Features\RegistrationWizardFeature;
use Nvade\NumerosisOnboarding\Livewire\Registration;

/*
 * Contributed through Numerosis::addCentralRoutes(), so this lands inside
 * core's own `Route::middleware('web')->domain($domain)` group — once per
 * entry in `tenancy.central_domains`. Registering it with a bare
 * `Route::get()` from this package's provider instead would answer on every
 * tenant subdomain too, and nothing would fail (see
 * tests/Feature/Support/SatelliteRouteContributionTest in core).
 *
 * The route *name* stays `tenants.create`: core links to it from four views
 * and from CompleteRedirectCheckout, all gated on
 * Nvade\Numerosis\Support\Tenancy\SelfServeRegistration::FEATURE.
 */
/*
 * Still gated on the feature, exactly as core's `routes/web.php` gated it
 * before the move: installing this package is not the same decision as
 * switching the wizard on, and a host can disable it through
 * `numerosis.features` without uninstalling. Reading the constant here is
 * safe — unlike core, this file cannot run without this package installed.
 */
if (Features::enabled(RegistrationWizardFeature::NAME)) {
    Route::livewire('/get-started', Registration::class)->name('tenants.create');
}
