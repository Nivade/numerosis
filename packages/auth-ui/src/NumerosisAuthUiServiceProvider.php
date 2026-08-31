<?php

declare(strict_types=1);

namespace Nvade\NumerosisAuthUi;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Numerosis;
use Nvade\NumerosisAuthUi\Features\SocialLoginFeature;
use Nvade\NumerosisAuthUi\Livewire\ConfirmPassword;
use Nvade\NumerosisAuthUi\Livewire\PasswordlessLogin;
use Nvade\NumerosisAuthUi\Livewire\VerifyEmail;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * The login, registration, password-reset and OAuth screens.
 *
 * Everything here is a *screen* plus the wiring that puts it on a URL. The
 * auth mechanics themselves — guards, `Actions\Auth\*`, `Models\SocialiteLogin`,
 * the social-account repository, `Support\Social\ConfiguredProviders`,
 * `TurnstileFeature` and the one-time-password migrations — stay in
 * `nvade/numerosis`, because core's own surfaces (invitation acceptance, the
 * tenant panel's connected-accounts manager, `Models\User`) use them whether
 * or not this package is installed.
 */
class NumerosisAuthUiServiceProvider extends PackageServiceProvider
{
    /**
     * Views register under **`numerosis::`**, the namespace core already
     * owns, for the same reason `nvade/numerosis-ui` does: `addNamespace()`
     * appends to a namespace's path list rather than replacing it, so the
     * moved components keep rendering `numerosis::livewire.auth.*` with no
     * view-string edits. Nothing is duplicated across the two packages —
     * these files were moved, not copied — and must stay that way, since
     * a same-named file resolves to whichever package registered first,
     * silently.
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('numerosis-auth-ui')
            ->hasViews('numerosis');
    }

    /**
     * Everything happens in the register phase, deliberately:
     *
     * - `Features::register()` must land before core's `packageBooted()`
     *   feature-boot loop, and core's provider boots first (Composer
     *   discovers `nvade/numerosis` ahead of this package). Every provider's
     *   `register()` runs before any provider's `boot()`, so this is the only
     *   phase that is reliably early enough.
     * - route contributions must land before `Numerosis::routes()` runs,
     *   which happens during boot via `withRouting(using: ...)`.
     */
    public function packageRegistered(): void
    {
        Features::register(SocialLoginFeature::class);

        $this->registerRoutes();
        $this->registerTenantPanelLogin();
    }

    /**
     * Central auth routes go through Phase 3's contribution seam rather than
     * core's `routes/web.php`, so they land inside the same
     * `Route::middleware('web')->domain($domain)` group, once per configured
     * central domain — the group a package cannot reproduce from outside.
     *
     * `Numerosis::authRoutesEnabled()` is still honoured here: a host that
     * calls `Numerosis::routes(withAuth: false)` because it keeps its own
     * Fortify/Breeze routes gets no `login`/`register`/`password.*` name
     * collision from this package either.
     */
    protected function registerRoutes(): void
    {
        $routes = dirname(__DIR__).'/routes';

        Numerosis::addCentralRoutes(function () use ($routes): void {
            if (! Numerosis::authRoutesEnabled()) {
                return;
            }

            require $routes.'/auth.php';
        });

        Numerosis::addTenantRoutes(function (): void {
            Route::middleware(['universal', 'auth:tenant'])->group(function (): void {
                Route::get('verify-email', VerifyEmail::class)
                    ->name('verification.notice');

                Route::get('confirm-password', ConfirmPassword::class)
                    ->name('password.confirm');
            });
        });
    }

    /**
     * Fills core's `numerosis.panels.tenant.login` seam, which core itself
     * now defaults to `null` — the tenant Filament panel serves this
     * package's login component when it is installed, and falls back to
     * Filament's own when it is not. A host that has already named its own
     * component wins; this never overwrites a configured value.
     */
    protected function registerTenantPanelLogin(): void
    {
        // Note for anyone reading `numerosis.panels` elsewhere: this write
        // goes through `Arr::set()`, which auto-vivifies every missing
        // segment. Provider *register* order between two discovered packages
        // is not ours to choose, so this can run before core's
        // `mergeConfigFrom()` and create a `numerosis.panels` array core
        // never supplied. That is harmless here — core's merge preserves the
        // existing sub-key — but it means the existence of that namespace is
        // **not** evidence core registered. `numerosis.features` is, and is
        // what nvade/numerosis-filament checks before registering a panel.
        // See .claude/rules/package-host-bootstrap.md for the version of this
        // hazard that truncated `tenancy.database`.
        if (Config::get('numerosis.panels.tenant.login') === null) {
            Config::set('numerosis.panels.tenant.login', PasswordlessLogin::class);
        }
    }
}
