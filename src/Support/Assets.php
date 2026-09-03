<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support;

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
 * that class that reached for `Vite` and the filesystem, neither of which the
 * bootstrap surface around it touches.
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
     * The `<link>`/`<script>` tags for the package's CSS and JS. Both ship
     * prebuilt and are copied to `public/vendor/numerosis` by
     * `vendor:publish --tag=numerosis-public-assets`, so no build step is
     * required.
     *
     * The URLs are emitted whether or not that publish has happened — the same
     * shape as the `FilamentAsset::getStyleHref()` pair this replaced when
     * Filament was dropped, and the reason `numerosis:install` verifies the
     * files exist rather than leaving a silent 404.
     *
     * Publishing `numerosis-assets` gives you `resources/js/numerosis.js` to
     * edit; once it is also an entry in your `vite.config.js`, your build is
     * used instead of the prebuilt bundle. Override the CSS through the
     * custom properties in `tokens.css` rather than by publishing it.
     */
    public static function tags(): Htmlable
    {
        $css = '<link href="'.e(self::publishedUrl('css')).'" rel="stylesheet" />';

        if (File::exists(resource_path('js/numerosis.js'))) {
            try {
                return new HtmlString($css.app(Vite::class)(['resources/js/numerosis.js'])->toHtml());
            } catch (ViteException) {
                // Published, but not an entry in the host's Vite manifest yet.
            }
        }

        $js = '<script src="'.e(self::publishedUrl('js')).'"></script>';

        return new HtmlString($css.$js);
    }

    /**
     * The public URL of one of the prebuilt bundles, as published by the
     * `numerosis-public-assets` group.
     */
    public static function publishedUrl(string $extension): string
    {
        return asset('vendor/numerosis/'.NumerosisServiceProvider::ASSET_ID.'.'.$extension);
    }

    /**
     * The published locations of the prebuilt bundles, as absolute paths.
     *
     * @return array<string, string>
     */
    public static function publishedPaths(): array
    {
        return [
            'css' => public_path('vendor/numerosis/'.NumerosisServiceProvider::ASSET_ID.'.css'),
            'js' => public_path('vendor/numerosis/'.NumerosisServiceProvider::ASSET_ID.'.js'),
        ];
    }
}
