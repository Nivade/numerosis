<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Concerns;

use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\View\Compilers\BladeCompiler;

/**
 * Adds what Filament's own theme does not cover, so both panels look like
 * the rest of the app: Flux's CSS and scripts, and the font files.
 *
 * Filament's component colours and radius come from the theme asset each
 * plugin applies, with no build step of your own. Override the palette
 * through the `--pref-*` custom properties in your published CSS.
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
