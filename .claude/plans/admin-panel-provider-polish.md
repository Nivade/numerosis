# AdminPanelProvider polish — thin-app

**Superseded (2026-08-11) by `.claude/plans/better-dx.md` Phase 2.** That
phase moves panel registration into the package itself
(`src/Providers/Filament/NumerosisAdminPanelProvider.php`) and deletes
thin-app's `app/Providers/Filament/AdminPanelProvider.php` — the file this
plan targets. Its four phases (`->spa()`, expanded `navigationGroups()`,
`->databaseNotifications()`, branding, `ActivityLogPlugin`, widget ordering)
are still wanted; they land on `NumerosisAdminPlugin` instead, where every
host gets them, as a follow-up once Phase 2's thin-app deletion has actually
happened. Do not execute this plan against the host file below — it is
about to disappear.

Target file: `~/repos/private/thin-app/app/Providers/Filament/AdminPanelProvider.php`
(separate repo from numerosis — plan filed here per project convention).

Filament v5.7.5. Deps confirmed present in thin-app: `alizharb/filament-activity-log`,
`laravel/reverb`, `openplain/filament-shadcn-theme`.

**Precondition — check before starting:** another Claude session was mid-flight
converting AdminPanel into a plugin at time this plan was written. Re-run
`git log -3 -- app/Providers/Filament/AdminPanelProvider.php` in thin-app right
before Phase 1 and diff against the snapshot below — don't touch if it's moved.

## File snapshot at plan time

```php
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Composer\InstalledVersions;
use Filament\Facades\Filament;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Nvade\Numerosis\Features\Ui\AdminPanelFeature;
use Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets\BillingStatsWidget;
use Nvade\Numerosis\Filament\Admin\Clusters\Billing\Widgets\RevenueChartWidget;
use Nvade\Numerosis\Http\Middleware\Authenticate;
use Nvade\Numerosis\Support\Features;

class AdminPanelProvider extends PanelProvider
{
    protected function packagePath(string $suffix): string
    {
        return InstalledVersions::getInstallPath('nvade/numerosis').'/src/Filament/Admin/'.$suffix;
    }

    public function register(): void
    {
        if (! Features::enabled(AdminPanelFeature::NAME)) {
            return;
        }

        Filament::registerPanel(
            fn (): Panel => $this->panel(Panel::make()),
        );
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->authGuard(config()->string('auth.defaults.guards.context.central'))
            ->login()
            ->registration()
            ->profile()
            ->colors(['primary' => Color::Amber])
            ->discoverResources(in: $this->packagePath('Resources'), for: 'Nvade\\Numerosis\\Filament\\Admin\\Resources')
            ->discoverClusters(in: $this->packagePath('Clusters'), for: 'Nvade\\Numerosis\\Filament\\Admin\\Clusters')
            ->discoverPages(in: $this->packagePath('Pages'), for: 'Nvade\\Numerosis\\Filament\\Admin\\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: $this->packagePath('Widgets'), for: 'Nvade\\Numerosis\\Filament\\Admin\\Widgets')
            ->widgets([AccountWidget::class, BillingStatsWidget::class, RevenueChartWidget::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->persistentMiddleware(['universal'])
            ->domains($this->centralDomains())
            ->authMiddleware([Authenticate::class])
            ->navigationGroups([
                NavigationGroup::make()->label('Customers')->collapsible(false),
            ]);
    }

    /** @return array<string> */
    protected function centralDomains(): array
    {
        /** @var array<string> $domains */
        $domains = resolve(Repository::class)->get('tenancy.central_domains', []);

        return $domains;
    }
}
```

## Do NOT touch

`->domains()`, `->persistentMiddleware()`, `->authGuard()`, `->registration()`,
`->profile()`, `->login()` — already correct, out of scope, likely what the
concurrent plugin-conversion session is mid-edit on.

Skip 2FA and locale middleware — no evidence host is multi-locale, no stated
threat model demanding 2FA now. Revisit only if requirements name them.

## Phase 1 — Critical

1. **`->spa()`** — add after `->profile()`.
   - Risk: `openplain/filament-shadcn-theme` may hook page transitions; check
     its README/issues for SPA-mode compatibility before shipping. If
     incompatible, skip and note why rather than silently breaking theme.
   - Verify: relevant Filament panel test, then manual click-through —
     open panel, click 3 resources, confirm no full reload, confirm shadcn
     styling intact.

## Phase 2 — High

2. **Expand `navigationGroups()`** beyond the single `Customers` group.
   Enumerate real resource set first:
   `find src/Filament/Admin/Resources -maxdepth 1 -type d` (in numerosis repo,
   since resources live in the package). Add groups for Billing,
   System/Modules as needed. Decide `->collapsible(false)` per group, not
   blanket — `Customers` already opts out deliberately, keep that pattern.
3. **`->databaseNotifications()` + `->databaseNotificationsPolling('30s')`**
   as a starting point (Phase 3 revisits polling vs Reverb push).
   - Verify: trigger a real notification path (e.g. a `Failed` provisioning
     notification per `.claude/rules/tenant-provisioning.md`) and confirm
     the bell shows it.

## Phase 3 — Medium

4. **Branding**: `->brandName()`, `->brandLogo()`, `->favicon()`. Pull real
   asset paths from `resources/` — don't invent filenames. Check whether
   the shadcn theme already brands the shell before adding redundant
   overrides.
5. **`->sidebarCollapsibleOnDesktop()`** — check shadcn theme doesn't
   already control this before adding.
6. **Wire `ActivityLogPlugin::make()`**. Confirm it isn't already registered
   somewhere:
   `grep -rn "ActivityLogPlugin" ~/repos/private/thin-app/app`
   If absent, add via `->plugin()`. Configure per the
   `alizharb/filament-activity-log` CLAUDE.md guideline (immutable_mode if
   compliance-relevant, risk config).
7. **Broadcast notifications over polling** — Reverb is already a
   dependency. Swap `databaseNotificationsPolling('30s')` for `null` +
   confirm broadcasting config is actually wired
   (`config/broadcasting.php`, `BroadcastServiceProvider`) before flipping —
   half-wired broadcast is worse than working polling.

## Phase 4 — Low / cleanup

8. `packagePath()` helper already centralizes the namespace — no dedup
   needed here (better than the numerosis stand-in file). No action.
9. Reorder widgets so stats lead: `->widgets([BillingStatsWidget::class,
   RevenueChartWidget::class, AccountWidget::class])`. Pure ordering
   change, near-zero risk.

## Sequencing / safety

- One phase = one commit, own message, own test run. Don't batch phases —
  SPA mode alone can regress the shadcn theme in ways only caught by
  clicking through; want it isolated for easy revert.
- After each phase: `vendor/bin/sail bin pint --dirty --format agent`, then
  targeted test run, then manual browser check scoped to that phase's
  surface (nav for phase 2, bell for phase 2.3, chrome for phase 3).
