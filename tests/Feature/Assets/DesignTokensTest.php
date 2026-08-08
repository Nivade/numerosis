<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Assets;

use Nvade\Numerosis\Tests\TestCase;

/**
 * Guards Phase 2 of .claude/plans/design-system-unification.md: the token
 * layer. These are raw-file assertions, not rendered-view ones — tokens.css
 * and filament-theme.css are never fetched through Blade/Vite in the
 * Workbench harness (see TestCase::stubViteManifest()'s docblock), so the
 * only honest thing to assert against is the CSS source itself.
 */
class DesignTokensTest extends TestCase
{
    private function tokensCss(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/tokens.css');
    }

    private function filamentThemeCss(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/filament-theme.css');
    }

    private function appCss(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/app.css');
    }

    public function test_app_css_stays_a_thin_entry_point_that_imports_tokens_from_vendor(): void
    {
        $css = $this->appCss();

        // Must import the *vendor* copy, not a relative './tokens.css' —
        // publishing copies resources/css wholesale, so a relative import
        // would resolve to the host's own frozen copy and never see a
        // package update again.
        $this->assertStringContainsString(
            "@import '../../vendor/nvade/numerosis/resources/css/tokens.css';",
            $css,
        );

        // The single token app.css used to carry directly (--font-sans) now
        // lives in tokens.css; app.css must not redeclare it.
        $this->assertStringNotContainsString('--font-sans:', $css);
    }

    public function test_tokens_css_declares_every_tier_3_token_light_value_on_bare_root(): void
    {
        $css = $this->tokensCss();

        [$root] = explode('.dark {', $css, 2);

        $tokens = [
            '--color-background', '--color-surface', '--color-surface-raised', '--color-surface-sunken',
            '--color-text', '--color-text-muted', '--color-text-subtle', '--color-text-inverted',
            '--color-border', '--color-border-strong', '--color-ring',
            '--color-primary', '--color-primary-hover', '--color-primary-contrast', '--color-primary-subtle',
            '--color-success-bg', '--color-success-border', '--color-success-text', '--color-success-icon',
            '--color-warning-bg', '--color-warning-border', '--color-warning-text', '--color-warning-icon',
            '--color-danger-bg', '--color-danger-border', '--color-danger-text', '--color-danger-icon',
            '--color-info-bg', '--color-info-border', '--color-info-text', '--color-info-icon',
            '--radius-sm', '--radius-md', '--radius-lg', '--radius-xl', '--radius-full',
            '--space-card', '--space-section', '--space-field',
            '--font-sans', '--font-display', '--font-mono',
            '--shadow-xs', '--shadow-sm', '--shadow-md', '--shadow-lg',
            '--border-width', '--border-width-strong',
            '--duration-fast', '--duration-base', '--duration-slow', '--ease-standard',
            '--z-dropdown', '--z-sticky', '--z-overlay', '--z-modal', '--z-toast', '--z-tooltip',
        ];

        foreach ($tokens as $token) {
            $this->assertStringContainsString("{$token}:", $root, "{$token} must have a light value on bare :root.");
        }
    }

    public function test_dark_block_only_redefines_tokens_that_already_exist_on_root(): void
    {
        $css = $this->tokensCss();

        [$root, $rest] = explode('.dark {', $css, 2);
        [$dark] = explode('}', $rest, 2);

        preg_match_all('/(--[a-z0-9-]+):/', $dark, $matches);

        foreach (array_unique($matches[1]) as $darkToken) {
            $this->assertStringContainsString(
                "{$darkToken}:",
                $root,
                "{$darkToken} is set under .dark but has no light value on bare :root — it would break with the preference toggled off.",
            );
        }

        // Pin the regression the layering rule guards against: the class
        // that made ui/card invisible in dark mode (Phase 0) was a surface
        // equal to the page's own dark background.
        $this->assertStringContainsString('--color-surface: var(--zinc-900);', $dark);
    }

    public function test_tokens_css_uses_zinc_as_the_only_grey_ramp(): void
    {
        $css = $this->tokensCss();

        $this->assertStringNotContainsString('gray-', $css);
        $this->assertStringNotContainsString('neutral-', $css);
        $this->assertStringNotContainsString('stone-', $css);
    }

    public function test_tokens_css_exposes_semantic_tokens_to_tailwind_via_theme_inline(): void
    {
        $css = $this->tokensCss();

        $this->assertStringContainsString('@theme inline {', $css);

        $themeBlock = substr($css, (int) strpos($css, '@theme inline {'));

        $this->assertStringContainsString('--color-surface: var(--color-surface);', $themeBlock);
        $this->assertStringContainsString('--radius-lg: var(--radius-lg);', $themeBlock);
    }

    public function test_filament_theme_css_is_a_standalone_bundle_that_imports_tokens_from_vendor(): void
    {
        $css = $this->filamentThemeCss();

        // viteTheme() replaces the panel's *entire* CSS bundle — unlike
        // tokens.css, this file has to stand alone.
        $this->assertStringContainsString("@import 'tailwindcss';", $css);
        $this->assertStringContainsString(
            "@import '../../vendor/filament/filament/resources/css/index.css';",
            $css,
        );

        // Same drift rule as app.css: the vendor path, not a relative
        // './tokens.css' that would resolve to the host's own frozen,
        // published copy once vendor:publish has run.
        $this->assertStringContainsString(
            "@import '../../vendor/nvade/numerosis/resources/css/tokens.css';",
            $css,
        );

        // Filament's own component CSS reads these raw ramp vars — see
        // vendor/filament/support/resources/css/index.css's @theme inline
        // block. Remapping them directly is what lets this file sidestep
        // FilamentColor::register()'s per-container memoisation.
        foreach (['--primary-500', '--gray-500', '--danger-500', '--warning-500', '--success-500', '--info-500'] as $var) {
            $this->assertStringContainsString("{$var}:", $css);
        }
    }

    public function test_filament_theme_loads_instrument_sans(): void
    {
        $this->assertStringContainsString("--font-family: 'Instrument Sans';", $this->filamentThemeCss());
    }

    public function test_filament_theme_gray_ramp_is_zinc_and_primary_ramp_is_hue_parameterized(): void
    {
        $css = $this->filamentThemeCss();

        $this->assertStringContainsString('--gray-500: var(--zinc-500);', $css);

        // Every shade parameterized by --pref-accent-hue (Phase 7), not a
        // fixed reference to a static blue ramp — otherwise a host's hue
        // override reaches the main app but never either Filament panel.
        foreach (['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950'] as $shade) {
            $this->assertMatchesRegularExpression(
                "/--primary-{$shade}: oklch\\([^)]*var\\(--pref-accent-hue\\)\\);/",
                $css,
                "--primary-{$shade} does not derive from --pref-accent-hue.",
            );
        }
    }

    /**
     * Phase 7: `--pref-accent-hue` used to be declared and never consumed
     * — `--color-primary` referenced a fixed `var(--accent-500)` instead,
     * so overriding the hue (as InstallNumerosisCommand's manual steps
     * tell a host to do) silently did nothing. Also guards the value
     * itself: Tailwind v4's palette is OKLCH-native, and an HSL-space hue
     * (217, what used to be here) produces the wrong color the moment
     * something actually reads it.
     */
    public function test_color_primary_is_parameterized_by_pref_accent_hue(): void
    {
        $css = $this->tokensCss();

        $this->assertStringContainsString('--pref-accent-hue: 259.815;', $css);
        $this->assertMatchesRegularExpression(
            '/--color-primary: oklch\([^)]*var\(--pref-accent-hue\)\);/',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/--color-primary-hover: oklch\([^)]*var\(--pref-accent-hue\)\);/',
            $css,
        );
    }
}
