<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Boot;

use LogicException;
use Nvade\Numerosis\Actions\Tenancy\AddTenantOwner;
use Nvade\Numerosis\Actions\Tenancy\CreateTenant;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The assertion is only worth having if it runs, so this boots an application
 * with the bad step list rather than calling the check directly — that is
 * `ConfiguredStepsTest`'s job.
 *
 * The misconfiguration is applied through `getEnvironmentSetUp()` and a static
 * flag, because `refreshApplication()` rebuilds the config from scratch and a
 * plain `Config::set()` in the test body would be thrown away.
 */
class MisconfiguredProvisioningStepsBootTest extends TestCase
{
    private static bool $misconfigure = false;

    public function test_a_wrong_shaped_first_step_fails_the_boot(): void
    {
        self::$misconfigure = true;

        try {
            $this->refreshApplication();

            $this->fail('Booting with a first provisioning step that cannot create the tenant should have thrown.');
        } catch (LogicException $e) {
            $this->assertStringContainsString(AddTenantOwner::class, $e->getMessage());
        } finally {
            self::$misconfigure = false;
            $this->refreshApplication();
        }
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        if (self::$misconfigure) {
            $app['config']->set('numerosis.tenancy.provisioning.steps', [
                AddTenantOwner::class,
                CreateTenant::class,
            ]);
        }
    }
}
