<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Support;

use Illuminate\Support\Facades\Config;
use LogicException;
use Nvade\Numerosis\Actions\Tenancy\AddTenantOwner;
use Nvade\Numerosis\Actions\Tenancy\CreateTenant;
use Nvade\Numerosis\Contracts\Tenancy\CreatesTenant;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\CompanyInfo;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\Payment;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\Plan;
use Nvade\Numerosis\Services\Tenancy\ConfiguredSteps;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `numerosis.tenancy.provisioning.steps[0]` is called
 * `run($registration): Tenant` synchronously; every later entry is
 * `run($tenant, $data): void` on the queue. Nothing enforced that, so a host
 * prepending its own step got a TypeError inside a queued job on its fifth
 * retry, far from the config it had edited.
 */
class ConfiguredStepsTest extends TestCase
{
    public function test_the_shipped_provisioning_steps_pass(): void
    {
        /** @var list<class-string> $steps */
        $steps = Config::array('numerosis.tenancy.provisioning.steps');

        ConfiguredSteps::assertTheFirstProvisioningStepCreatesTenant($steps);

        $this->assertTrue(is_a($steps[0], CreatesTenant::class, true));
    }

    public function test_a_first_step_that_does_not_create_the_tenant_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/must implement/');

        ConfiguredSteps::assertTheFirstProvisioningStepCreatesTenant([
            AddTenantOwner::class,
            CreateTenant::class,
        ]);
    }

    public function test_an_empty_provisioning_step_list_is_refused(): void
    {
        $this->expectException(LogicException::class);

        ConfiguredSteps::assertTheFirstProvisioningStepCreatesTenant([]);
    }

    public function test_the_shipped_registration_steps_pass(): void
    {
        /** @var list<class-string> $steps */
        $steps = Config::array('numerosis.tenancy.registration.steps');

        ConfiguredSteps::assertARegistrationStepProvidesTenantIdentity($steps);

        $this->assertNotSame([], $steps);
    }

    public function test_a_registration_step_list_with_no_identity_source_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/identifier or display name/');

        ConfiguredSteps::assertARegistrationStepProvidesTenantIdentity([Plan::class, Payment::class]);
    }

    public function test_one_identity_step_anywhere_in_the_list_is_enough(): void
    {
        ConfiguredSteps::assertARegistrationStepProvidesTenantIdentity([
            Plan::class,
            CompanyInfo::class,
            Payment::class,
        ]);

        $this->addToAssertionCount(1);
    }
}
