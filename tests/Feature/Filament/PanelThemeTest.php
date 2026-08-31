<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Filament;

use Filament\Facades\Filament;
use Nvade\Numerosis\NumerosisServiceProvider;
use Nvade\Numerosis\Tests\TestCase;
use Nvade\NumerosisFilament\Providers\NumerosisAdminPanelProvider;
use Nvade\NumerosisFilament\Providers\NumerosisTenantPanelProvider;
use ReflectionClass;

/**
 * Phase 4 of .claude/plans/design-system-unification.md: both panels share
 * one theme instead of admin's amber and tenant's rose. Guards the two
 * failure shapes that are otherwise invisible until someone loads the panel
 * in a browser — a missing `->theme()` renders stock Filament silently, and
 * a `->colors()` call surviving on either workbench provider would fight
 * `FilamentColor::register()`'s per-container memoisation for nothing
 * (Phase 1 audit §1.7) rather than actually override the token remap.
 */
class PanelThemeTest extends TestCase
{
    public function test_both_panels_register_the_filament_theme_asset(): void
    {
        foreach (['admin', 'tenantAdmin'] as $panelId) {
            $panel = Filament::getPanel($panelId);

            $this->assertSame(
                NumerosisServiceProvider::THEME_ID,
                $panel->getTheme()->getId(),
                "Panel [{$panelId}] does not register ->theme(NumerosisServiceProvider::THEME_ID).",
            );
        }
    }

    /**
     * Both plugin classes compose the same trait rather than each
     * hand-rolling the render hooks — the thing that let the admin panel go
     * without any of them at all (Phase 1 audit §1.4: "no ->viteTheme(),
     * no render hooks, nothing from the main app reaches it").
     */
    public function test_both_plugins_share_the_same_theme_concern(): void
    {
        $trait = 'Nvade\NumerosisFilament\Concerns\AppliesNumerosisPanelTheme';

        foreach (['Nvade\NumerosisFilament\NumerosisAdminPlugin', 'Nvade\NumerosisFilament\NumerosisTenantPlugin'] as $plugin) {
            $this->assertContains(
                $trait,
                class_uses_recursive($plugin),
                "{$plugin} does not use {$trait}.",
            );
        }
    }

    public function test_neither_panel_provider_calls_colors(): void
    {
        // Resolved by reflection, not by a hardcoded path: both providers moved
        // to nvade/numerosis-filament, and a path that no longer exists makes
        // this fail loudly here but would have gone *vacuous* in a scan-style
        // guard. See .claude/rules/package-split.md.
        foreach ([
            (new ReflectionClass(NumerosisAdminPanelProvider::class))->getFileName(),
            (new ReflectionClass(NumerosisTenantPanelProvider::class))->getFileName(),
        ] as $path) {
            $this->assertIsString($path);

            // Comment-stripped: both files' docblocks explain in prose *why*
            // there is no ->colors() call, which would otherwise trip this
            // assertion on the very sentence documenting the fix.
            $source = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($path)) ?? '';

            $this->assertStringNotContainsString(
                '->colors(',
                $source,
                basename($path).' still calls ->colors() — that fights FilamentColor::register()\'s memoisation instead of overriding anything now that ->theme() remaps the same vars from tokens.css.',
            );
        }
    }

    public function test_the_dead_tenant_theme_css_is_gone(): void
    {
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 3).'/resources/css/filament/tenantAdmin/theme.css',
            'The commented-out, unreferenced Filament v4 theme file should have been deleted in Phase 4.',
        );
    }
}
