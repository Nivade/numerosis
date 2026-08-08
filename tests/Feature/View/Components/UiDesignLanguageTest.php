<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\View\Components;

use Nvade\Numerosis\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Guards the visual conventions the pages in resources/views establish, which
 * the components under resources/views/components had drifted from.
 *
 * These are deliberately assertions about *class strings*, not about markup
 * shape. Every one of them corresponds to a specific disagreement between a
 * component and the pages:
 *
 * - ui/card rendered `dark:bg-zinc-800`, the same value every layout gives
 *   <body>, so in dark mode a card had no surface at all and only its border
 *   separated it from the page.
 * - ui/alert, ui/badge and ui/info-box each carried their own semantic
 *   palette. They disagreed: green/yellow, green/yellow and green/amber, for
 *   one set of meanings. The pages use emerald and amber and never use green
 *   or yellow.
 * - ui/empty-state, ui/heading and ui/subheading mixed the gray ramp into a
 *   zinc codebase.
 *
 * A rendered class string is the honest thing to assert here: it is what the
 * browser actually receives, and unlike a screenshot it fails loudly the
 * moment someone reintroduces the old value.
 */
class UiDesignLanguageTest extends TestCase
{
    /**
     * The body background every layout sets. A surface equal to this value is
     * invisible against the page, which is the bug this pins.
     */
    private const BODY_DARK = 'dark:bg-zinc-800';

    public function test_card_surfaces_sit_below_the_page_background_in_dark_mode(): void
    {
        $card = $this->blade('<x-numerosis::ui.card>content</x-numerosis::ui.card>');

        $card->assertSee('dark:bg-zinc-900', false);
        $card->assertDontSee(self::BODY_DARK, false);
    }

    public function test_card_uses_the_translucent_hairline_border_the_pages_use(): void
    {
        $card = $this->blade('<x-numerosis::ui.card>content</x-numerosis::ui.card>');

        $card->assertSee('dark:border-white/10', false);
        $card->assertDontSee('dark:border-zinc-700', false);
    }

    public function test_card_uses_the_page_card_radius(): void
    {
        $this->blade('<x-numerosis::ui.card>content</x-numerosis::ui.card>')
            ->assertSee('rounded-xl', false);
    }

    /**
     * Phase 3 moved badge and alert off literal hue classes onto the
     * `--color-success-*` tokens (resources/css/tokens.css) — emerald now
     * lives one layer down, in the token's definition, not in the component
     * markup. `success-` is the shared name both components render.
     *
     * @return list<array{string}>
     */
    public static function positiveSurfaces(): array
    {
        return [
            ['<x-numerosis::ui.badge variant="success">ok</x-numerosis::ui.badge>'],
            ['<x-numerosis::ui.alert type="success" message="ok" />'],
            ['<x-numerosis::ui.info-box type="success">ok</x-numerosis::ui.info-box>'],
        ];
    }

    #[DataProvider('positiveSurfaces')]
    public function test_positive_state_is_the_success_token_everywhere(string $template): void
    {
        $rendered = $this->blade($template);

        $rendered->assertSee('success-', false);
        $rendered->assertDontSee('emerald-', false);
        $rendered->assertDontSee('green-', false);
    }

    /**
     * @return list<array{string}>
     */
    public static function warningSurfaces(): array
    {
        return [
            ['<x-numerosis::ui.badge variant="warning">careful</x-numerosis::ui.badge>'],
            ['<x-numerosis::ui.alert type="warning" message="careful" />'],
            ['<x-numerosis::ui.info-box type="warning">careful</x-numerosis::ui.info-box>'],
        ];
    }

    #[DataProvider('warningSurfaces')]
    public function test_warning_state_is_the_warning_token_everywhere(string $template): void
    {
        $rendered = $this->blade($template);

        $rendered->assertSee('warning-', false);
        $rendered->assertDontSee('amber-', false);
        $rendered->assertDontSee('yellow-', false);
    }

    /**
     * `danger` is ui/info-box's name for the red variant and `error` is
     * ui/alert's. Both have to resolve to the same thing now that one
     * component implements the other.
     */
    public function test_danger_and_error_name_the_same_variant(): void
    {
        $this->blade('<x-numerosis::ui.info-box type="danger">bad</x-numerosis::ui.info-box>')
            ->assertSee('danger-', false);

        $this->blade('<x-numerosis::ui.alert type="error" message="bad" />')
            ->assertSee('danger-', false);
    }

    public function test_info_box_still_renders_its_title_and_body_through_alert(): void
    {
        $rendered = $this->blade(
            '<x-numerosis::ui.info-box type="warning" title="Heads up">Body copy</x-numerosis::ui.info-box>',
        );

        $rendered->assertSee('Heads up');
        $rendered->assertSee('Body copy');
    }

    /**
     * @return list<array{string}>
     */
    public static function neutralComponents(): array
    {
        return [
            ['<x-numerosis::ui.empty-state title="Nothing here" description="Add one to begin." />'],
            ['<x-numerosis::ui.heading>Title</x-numerosis::ui.heading>'],
            ['<x-numerosis::ui.subheading>Subtitle</x-numerosis::ui.subheading>'],
            ['<x-numerosis::ui.badge>Neutral</x-numerosis::ui.badge>'],
        ];
    }

    #[DataProvider('neutralComponents')]
    public function test_neutral_components_use_the_zinc_ramp_only(string $template): void
    {
        $rendered = $this->blade($template);

        $rendered->assertDontSee('gray-', false);
        $rendered->assertDontSee('neutral-', false);
        $rendered->assertDontSee('stone-', false);
    }

    /**
     * The wash was written as `var(--color-blue-500)/15` on welcome.blade.php.
     * A bare colour followed by `/15` is not a valid gradient stop, so the
     * browser dropped the declaration and that page rendered no wash at all —
     * silently, since an invalid background is simply absent.
     */
    public function test_hero_gradient_emits_a_valid_colour_stop(): void
    {
        $rendered = $this->blade('<x-numerosis::ui.hero-gradient />');

        $rendered->assertSee('color-mix(in_oklab,var(--color-blue-500)_15%,transparent)', false);
        $rendered->assertDontSee('var(--color-blue-500)/15', false);
    }

    public function test_hero_gradient_offers_a_quieter_variant_for_long_form_pages(): void
    {
        $this->blade('<x-numerosis::ui.hero-gradient intensity="soft" />')
            ->assertSee('var(--color-blue-500)_10%', false);
    }

    public function test_hero_gradient_is_decorative_and_hidden_from_assistive_tech(): void
    {
        $this->blade('<x-numerosis::ui.hero-gradient />')
            ->assertSee('aria-hidden', false);
    }

    /**
     * Colours are an allowlist because Tailwind generates only the classes it
     * can find spelled out in source — a class assembled from an interpolated
     * prop is a class that never gets built. An unknown colour therefore has
     * to fall back to one that exists rather than emit dead markup.
     */
    public function test_icon_tile_falls_back_to_a_generated_colour(): void
    {
        $this->blade('<x-numerosis::ui.icon-tile icon="bolt" color="chartreuse" />')
            ->assertSee('bg-blue-500/10', false);
    }

    public function test_icon_tile_renders_the_allowlisted_accents(): void
    {
        $this->blade('<x-numerosis::ui.icon-tile icon="bolt" color="purple" />')
            ->assertSee('bg-purple-500/10', false);
    }

    public function test_section_carries_the_page_container_rhythm(): void
    {
        $rendered = $this->blade('<x-numerosis::ui.section>body</x-numerosis::ui.section>');

        $rendered->assertSee('max-w-7xl', false);
        $rendered->assertSee('px-4 sm:px-6 lg:px-8', false);
        $rendered->assertSee('py-12 sm:py-20', false);
    }

    public function test_section_offers_the_narrower_measure_the_legal_pages_use(): void
    {
        $this->blade('<x-numerosis::ui.section width="prose">body</x-numerosis::ui.section>')
            ->assertSee('max-w-4xl', false);
    }
}
