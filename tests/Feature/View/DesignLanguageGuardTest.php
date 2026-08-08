<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\View;

use Nvade\Numerosis\Tests\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Phase 3 guard (.claude/plans/design-system-unification.md): the 57-view
 * gray/neutral/stone → zinc sweep and the 8-value → 5-token radius collapse
 * are mechanical edits with nothing structural stopping them from
 * regressing the next time someone pastes a class from an old file or a
 * design tool. This is that stop.
 *
 * Matches against Blade/HTML comment-stripped source, not raw file
 * contents — several files carry a comment documenting the *old*,
 * now-fixed value (e.g. ui/heading.blade.php's "gray-900 was the last gray
 * in the heading path"), and matching those literally would make this test
 * fail on the very commit that fixed the thing it guards.
 */
class DesignLanguageGuardTest extends TestCase
{
    /**
     * `rounded-2xl` survives in exactly these views, on exactly the "media
     * panel" elements the Phase 0 canonical table (§ Radius) carves out for
     * media/hero content — everything else collapsed to --radius-xl in
     * Phase 3. `rounded-3xl` has no such exception: every occurrence
     * collapsed.
     */
    private const ROUNDED_2XL_ALLOWED_IN = [
        'about.blade.php',
        'features.blade.php',
        'welcome.blade.php',
    ];

    /** @return list<\Symfony\Component\Finder\SplFileInfo> */
    private function viewFiles(): array
    {
        $root = dirname(__DIR__, 3).'/resources/views';

        $finder = (new Finder)->files()->in($root)->name('*.blade.php');

        return iterator_to_array($finder, false);
    }

    private function stripComments(string $contents): string
    {
        $contents = preg_replace('/\{\{--.*?--\}\}/s', '', $contents) ?? $contents;

        return preg_replace('/<!--.*?-->/s', '', $contents) ?? $contents;
    }

    public function test_no_view_uses_gray_neutral_or_stone_instead_of_zinc(): void
    {
        $files = $this->viewFiles();

        $this->assertNotEmpty($files, 'Scanned no view files — the resources/views path above is wrong.');

        foreach ($files as $file) {
            $code = $this->stripComments($file->getContents());

            foreach (['gray', 'neutral', 'stone'] as $ramp) {
                $this->assertDoesNotMatchRegularExpression(
                    "/\\b{$ramp}-[0-9]+\\b/",
                    $code,
                    "{$file->getRelativePathname()} uses the {$ramp} ramp — zinc is the only grey ramp resources/views uses.",
                );
            }
        }
    }

    public function test_rounded_2xl_only_survives_on_the_documented_media_panels(): void
    {
        $files = $this->viewFiles();

        foreach ($files as $file) {
            $code = $this->stripComments($file->getContents());

            if (! str_contains($code, 'rounded-2xl')) {
                continue;
            }

            $this->assertContains(
                $file->getFilename(),
                self::ROUNDED_2XL_ALLOWED_IN,
                "{$file->getRelativePathname()} uses rounded-2xl — collapse it to rounded-xl (the 5-token radius scale), or add it to DesignLanguageGuardTest::ROUNDED_2XL_ALLOWED_IN with a reason if it's genuinely a media/hero panel.",
            );
        }
    }

    public function test_no_view_uses_rounded_3xl(): void
    {
        $files = $this->viewFiles();

        foreach ($files as $file) {
            $code = $this->stripComments($file->getContents());

            $this->assertStringNotContainsString(
                'rounded-3xl',
                $code,
                "{$file->getRelativePathname()} uses rounded-3xl — the 5-token radius scale tops out at --radius-xl; collapse it.",
            );
        }
    }

    /**
     * Phase 6: `->emptyStateIcon()` took a mix of raw `'heroicon-o-*'`
     * strings and the `Heroicon` enum across the Filament resources — one
     * vocabulary, the CLAUDE.md-documented convention
     * (`Filament\Support\Icons\Heroicon` enum), not two spellings of the
     * same icon.
     */
    public function test_no_filament_resource_uses_a_raw_heroicon_string_for_empty_state_icon(): void
    {
        $finder = (new Finder)->files()->in(dirname(__DIR__, 3).'/src')->name('*.php');

        foreach ($finder as $file) {
            $this->assertDoesNotMatchRegularExpression(
                "/emptyStateIcon\\(\\s*['\"]heroicon-/",
                $file->getContents(),
                "{$file->getRelativePathname()} passes a raw 'heroicon-o-*' string to emptyStateIcon() — use the Heroicon enum instead.",
            );
        }
    }

    /**
     * Phase 7: `focus-ring` (tokens.css's `@utility focus-ring`) is the one
     * focus-visible treatment resources/views uses — two files had their
     * own hand-rolled `focus-visible:ring-*` before this (a subtle
     * black/white ring on the OAuth buttons, a raw `--primary-500` ring on
     * a Filament-panel view), same drift class Phase 0 already fixed once
     * for `ui/alert`'s own focus ring.
     */
    public function test_no_view_hand_rolls_its_own_focus_visible_ring(): void
    {
        $files = $this->viewFiles();

        foreach ($files as $file) {
            $code = $this->stripComments($file->getContents());

            $this->assertDoesNotMatchRegularExpression(
                '/focus-visible:ring-|focus:ring-[a-z]/',
                $code,
                "{$file->getRelativePathname()} hand-rolls a focus ring instead of using the shared `focus-ring` utility.",
            );
        }
    }

    /**
     * Phase 6: `<x-filament::button>` already derives `wire:target` from its
     * own `wire:click` and renders `wire:loading.attr="disabled"` plus a
     * real spinner (`Filament\Support\generate_loading_indicator_html()`) —
     * `vendor/filament/support/resources/views/components/button/index.blade.php`.
     * A manual `wire:loading.attr="disabled"` on the same tag is not just
     * redundant, it's *worse*: it disables the button with no spinner,
     * which is exactly the "no shared spinner" divergence this phase
     * closes. Two Numerosis-owned pages had this before the fix
     * (marketplace, module-detail); this stops a third.
     */
    public function test_no_filament_button_manually_duplicates_its_own_loading_indicator(): void
    {
        $files = $this->viewFiles();

        foreach ($files as $file) {
            $code = $this->stripComments($file->getContents());

            if (! preg_match_all('/<x-filament::button\b.*?>/s', $code, $matches)) {
                continue;
            }

            foreach ($matches[0] as $tag) {
                $this->assertStringNotContainsString(
                    'wire:loading',
                    $tag,
                    "{$file->getRelativePathname()} sets wire:loading manually on an <x-filament::button> — the component already handles it (and renders a spinner the manual version doesn't). Remove the manual attribute.",
                );
            }
        }
    }
}
