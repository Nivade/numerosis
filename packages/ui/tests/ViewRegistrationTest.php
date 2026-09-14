<?php

declare(strict_types=1);

namespace Nvade\NumerosisUi\Tests;

use Illuminate\Support\Facades\View;

/**
 * This package ships views and nothing else, so what there is to test is that
 * they are reachable by the names the rest of the Numerosis packages use, and
 * that this package genuinely stands alone.
 */
class ViewRegistrationTest extends TestCase
{
    public function test_it_serves_its_views_under_the_shared_numerosis_namespace(): void
    {
        $this->assertTrue(View::exists('numerosis::components.ui.card'));
        $this->assertTrue(View::exists('numerosis::components.icons.google'));
        $this->assertTrue(View::exists('numerosis::components.placeholder-pattern'));
    }

    public function test_its_components_render_by_the_tag_name_other_packages_use(): void
    {
        $this->blade('<x-numerosis::ui.card>content</x-numerosis::ui.card>')
            ->assertSee('content')
            ->assertSee('rounded-xl', false);
    }

    /**
     * The lucide icons Flux does not ship. Registered from `booted()` so Flux's
     * own paths are searched first — a Flux-supplied icon of the same name
     * must keep winning over this package's fallback.
     */
    public function test_it_registers_its_flux_icon_fallbacks(): void
    {
        $this->assertTrue(View::exists('numerosis::flux.icon.layout-grid'));

        // Registered as an *anonymous component path* under the `flux` prefix
        // too, which is how the views actually address them (`<flux:icon.layout-grid />`).
        $this->blade('<flux:icon.layout-grid />')->assertSee('svg', false);
    }

    /**
     * Each icon file is its own paths and nothing else; the svg chrome around
     * them is `ui.lucide-icon`. Asserting a path, the stroke width the variant
     * picks and the size class is what makes that split visible — before it,
     * every icon repeated all three and nothing rendered them in a test.
     */
    public function test_an_icon_renders_its_paths_inside_the_shared_chrome(): void
    {
        $this->blade('<flux:icon.layout-grid variant="micro" />')
            ->assertSee('<rect width="7" height="7" x="3" y="3" rx="1" />', false)
            ->assertSee('stroke-width="2.5"', false)
            ->assertSee('size-4', false);

        $this->blade('<flux:icon.folder-git-2 />')
            ->assertSee('<circle cx="20" cy="19" r="2" />', false)
            ->assertSee('stroke-width="2"', false);
    }

    /*
     * What may live here at all — no view or class naming Nvade\Numerosis,
     * calling tenancy(), or generating a named route — is enforced in the root
     * suite by Nvade\Numerosis\Tests\Feature\PackageBoundariesTest, over this
     * package's src/ as well as its views.
     */
}
