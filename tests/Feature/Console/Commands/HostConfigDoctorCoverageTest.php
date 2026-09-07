<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Console\Commands;

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Commands\InstallNumerosisCommand;
use Nvade\Numerosis\Support\HostConfig;
use Nvade\Numerosis\Tests\TestCase;
use ReflectionClass;

/**
 * `HostConfig` writes the host's config and `numerosis:install` re-checks it,
 * through two hand-written surfaces over the same key set. A key added to one
 * was silently unverified by the other, and a default changed in one produced
 * a false failure in the other.
 *
 * This drives `HostConfig::apply()` from a blank slate so it writes everything
 * it can, and holds the result against the doctor's own map.
 */
class HostConfigDoctorCoverageTest extends TestCase
{
    public function test_the_doctor_covers_every_key_host_config_writes(): void
    {
        $uncovered = array_values(array_filter(
            $this->keysHostConfigWrites(),
            fn (string $key): bool => ! $this->isCovered($key),
        ));

        $this->assertSame([], $uncovered, 'These keys are written by HostConfig and absent from InstallNumerosisCommand::VERIFIED_CONFIG_KEYS.');
    }

    public function test_every_mapped_check_exists(): void
    {
        $command = new ReflectionClass(InstallNumerosisCommand::class);

        $missing = array_values(array_filter(
            array_unique(array_values(InstallNumerosisCommand::VERIFIED_CONFIG_KEYS)),
            fn (string $method): bool => ! $command->hasMethod($method),
        ));

        $this->assertSame([], $missing, 'These checks are mapped but no longer exist.');
    }

    /**
     * Blanking the keys first is what makes this exhaustive: `apply()` records
     * only what it changed, and `TestCase` has already applied most of them.
     *
     * List-shaped keys are blanked to `[]` rather than null, because
     * `Config::array()` throws on an explicitly-null value instead of falling
     * back to its default.
     *
     * @return list<string>
     */
    private function keysHostConfigWrites(): array
    {
        $lists = [
            'auth.passwords',
            'fortify.features',
            'tenancy.bootstrappers',
            'tenancy.central_domains',
            'tenancy.filesystem.disks',
            'tenancy.migration_parameters',
            'tenancy.seeder_parameters',
        ];

        foreach (array_keys(InstallNumerosisCommand::VERIFIED_CONFIG_KEYS) as $key) {
            $key = rtrim($key, '.');

            // Left alone: HostConfig only defines `central` while the key is
            // absent, and blanking it to null leaves it absent-but-present, so
            // apply() would skip it and the harness would lose its connection.
            if ($key === 'database.connections.central') {
                continue;
            }

            Config::set($key, in_array($key, $lists, true) ? [] : null);
        }

        foreach ($lists as $key) {
            Config::set($key, []);
        }

        HostConfig::apply();

        return HostConfig::applied();
    }

    private function isCovered(string $key): bool
    {
        foreach (array_keys(InstallNumerosisCommand::VERIFIED_CONFIG_KEYS) as $covered) {
            if ($key === $covered || (str_ends_with($covered, '.') && str_starts_with($key, $covered))) {
                return true;
            }
        }

        return false;
    }
}
