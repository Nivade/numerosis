<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Admin;

use Nvade\Numerosis\Tests\TestCase;
use Symfony\Component\Finder\Finder;

class ImpersonationBannerTest extends TestCase
{
    /**
     * Enumerated from disk, not listed, so a new layout with no banner fails
     * this test rather than silently reintroducing the gap 8.3 closed.
     */
    public function test_every_tenant_layout_renders_the_impersonation_banner(): void
    {
        $layouts = Finder::create()
            ->files()
            ->name('*.blade.php')
            ->in(__DIR__.'/../../../resources/views/layouts/app');

        $checked = 0;

        foreach ($layouts as $layout) {
            $checked++;

            $this->assertStringContainsString(
                'x-numerosis::impersonation.banner',
                $layout->getContents(),
                "{$layout->getFilename()} does not render the impersonation banner.",
            );
        }

        $this->assertGreaterThan(0, $checked, 'No tenant layouts were found to check.');
    }
}
