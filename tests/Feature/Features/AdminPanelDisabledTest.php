<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Filament\Facades\Filament;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Tests\TestCase;

class AdminPanelDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        Features::forceForTesting([]);

        parent::setUp();
    }

    public function test_the_admin_panel_is_not_registered_when_disabled(): void
    {
        $this->assertArrayNotHasKey('admin', Filament::getPanels());
    }
}
