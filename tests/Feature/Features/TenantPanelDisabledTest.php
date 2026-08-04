<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Nvade\Numerosis\Support\Features;
use Filament\Facades\Filament;
use Nvade\Numerosis\Tests\TestCase;

class TenantPanelDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        Features::forceForTesting([]);

        parent::setUp();
    }

    public function test_the_tenant_panel_is_not_registered_when_disabled(): void
    {
        $this->assertArrayNotHasKey('tenantAdmin', Filament::getPanels());
    }
}
