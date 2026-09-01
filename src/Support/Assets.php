<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support;

use Filament\Support\Facades\FilamentAsset;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Vite;
use Illuminate\Foundation\ViteException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\HtmlString;
use Nvade\Numerosis\NumerosisServiceProvider;

/**
 * The package's non-panel CSS and JS: where they are published from, and the
 * tags that load them.
 *
 * Split out of {@see Numerosis} on 2026-09-01, the third cut after
 * {@see ModelResolver} and {@see Contributions}. Small, but its own audience —
 * a host publishing and rebuilding front-end assets — and the only part of
 * that class that reached for `Filament\`, `Vite` and the filesystem, none of
 * which the bootstrap surface around it touches.
 *
 * `Numerosis::assetSourcePaths()` / `::assetTags()` still exist and delegate
 * here; `resources/views/partials/styles.blade.php` and
 * `numerosis:install` call them under those names.
 */
final class Assets
{
    /**
     * The `numerosis-assets` publish group, as source => target directory.
     * `numerosis:install` diffs published copies against the same map to
     * report when yours has fallen behind the package's.
     *
     * @return array<string, string>
     */
    public static function sourcePaths(): array
    {
        $base = dirname(__DIR__, 2);

        return [
            $base.'/resources/css' => resource_path('css'),
            $base.'/resources/js' => resource_path('js'),
        ];
    }

    /**
     * The `<link>`/`<script>` tags for the package's non-panel CSS and JS.
     * Both ship prebuilt and are served by `filament:assets`, so no build
     * step is required.
     *
     * Publishing `numerosis-assets` gives you `resources/js/numerosis.js` to
     * edit; once it is also an entry in your `vite.config.js`, your build is
     * used instead of the prebuilt bundle. Override the CSS through the
     * custom properties in `tokens.css` rather than by publishing it.
     */
    public static function tags(): Htmlable
    {
        $css = '<link href="'.e(FilamentAsset::getStyleHref(NumerosisServiceProvider::ASSET_ID, 'nvade/numerosis')).'" rel="stylesheet" />';

        if (File::exists(resource_path('js/numerosis.js'))) {
            try {
                return new HtmlString($css.app(Vite::class)(['resources/js/numerosis.js'])->toHtml());
            } catch (ViteException) {
                // Published, but not an entry in the host's Vite manifest yet.
            }
        }

        $js = '<script src="'.e(FilamentAsset::getScriptSrc(NumerosisServiceProvider::ASSET_ID, 'nvade/numerosis')).'"></script>';

        return new HtmlString($css.$js);
    }
}
