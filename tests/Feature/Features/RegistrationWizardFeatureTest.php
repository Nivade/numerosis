<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Nvade\Numerosis\Tests\TestCase;

class RegistrationWizardFeatureTest extends TestCase
{
    public function test_it_registers_the_route_when_enabled(): void
    {
        $this->assertTrue(Route::has('tenants.create'));
    }

    public function test_it_registers_the_livewire_component_when_enabled(): void
    {
        Livewire::test('tenant-registration')->assertOk();
    }
}
