<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Console\Commands;

use Illuminate\Testing\PendingCommand;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Tests\TestCase;
use stdClass;

/**
 * Covers the model-override checks only. `--verify-only` is what makes this
 * testable: the default run publishes files and appends to the host's `.env`,
 * neither of which belongs in a test process.
 */
class InstallNumerosisCommandTest extends TestCase
{
    public function test_it_passes_when_every_model_override_names_a_real_subclass(): void
    {
        $this->install()->assertSuccessful();
    }

    public function test_it_fails_when_a_model_override_names_a_class_that_does_not_exist(): void
    {
        config()->set('numerosis.models.'.Tenant::class, 'App\\Models\\Central\\NoSuchTenant');

        $this->install()
            ->expectsOutputToContain('does not exist — check NUMEROSIS_MODEL_TENANT')
            ->assertFailed();
    }

    public function test_it_fails_when_a_model_override_is_not_a_subclass_of_the_package_model(): void
    {
        config()->set('numerosis.models.'.Tenant::class, stdClass::class);

        $this->install()
            ->expectsOutputToContain('does not extend')
            ->assertFailed();
    }

    /**
     * The supported no-stub shape (D8): no override, no published stub, so
     * package code runs on the package's own models and nothing is wrong.
     */
    public function test_it_passes_when_no_override_is_set_and_no_stub_is_published(): void
    {
        config()->set('numerosis.models', []);

        $this->install()->assertSuccessful();
    }

    /**
     * `artisan()` is typed `PendingCommand|int` — it returns the int only once
     * expectations have been run. Narrowing here keeps every test a single
     * chained call without a baseline entry.
     */
    private function install(): PendingCommand
    {
        $command = $this->artisan('numerosis:install', ['--verify-only' => true]);

        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
