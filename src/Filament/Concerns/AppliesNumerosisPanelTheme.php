<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Concerns;

use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\View\Compilers\BladeCompiler;

/**
 * The render hooks both panels need so their chrome shares the main app's
 * styling. Previously lived only on {@see \Nvade\Numerosis\Filament\NumerosisTenantPlugin}
 * — the admin panel had none of it (Phase 1 audit §1.4: "no `->viteTheme()`,
 * no render hooks, nothing from the main app reaches it"). Extracted so
 * admin and tenant cannot drift the way the two passwordless-login
 * components did (.claude/rules/auth-login.md).
 *
 * `->viteTheme('resources/css/filament-theme.css')` (registered by each
 * plugin directly, not here — {@see Panel::viteTheme()} is a single value,
 * not a stack, so there is nothing to "extract" about the call itself)
 * handles Filament's own fi-* component colours and radius. What is
 * extracted here is everything Filament's theme replacement does **not**
 * cover: Flux's CSS (panel pages compose `<x-numerosis::ui.*>` and
 * `flux:icon`, neither of which `filament-theme.css` builds), the
 * Instrument Sans font files themselves (`--font-family` in
 * filament-theme.css only names the family; the browser still needs the
 * `<link>`), and Flux's own script bundle for any Flux component that needs
 * Alpine wiring. `--pref-*` overrides are a host-level concern
 * (design-system-unification Phase 5) — a consumer sets them once, in its
 * own published CSS after the `tokens.css` import, not per-request from
 * here.
 */
trait AppliesNumerosisPanelTheme
{
    protected function applyNumerosisPanelTheme(Panel $panel): Panel
    {
        return $panel
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => resolve(BladeCompiler::class)->render('@include(\'numerosis::partials.fonts\')'),
            )
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => resolve(BladeCompiler::class)->render('@include(\'numerosis::partials.styles\')'),
            )
            ->renderHook(
                PanelsRenderHook::SCRIPTS_AFTER,
                fn (): string => resolve(BladeCompiler::class)->render('@fluxScripts'),
            );
    }
}
