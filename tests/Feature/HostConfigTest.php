<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use Illuminate\Foundation\Auth\User as GenericUser;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Services\Tenancy\Bootstrappers\AuthGuardBootstrapper;
use Nvade\Numerosis\Services\Tenancy\Bootstrappers\SpatiePermissionsBootstrapper;
use Nvade\Numerosis\Support\HostConfig;
use Nvade\Numerosis\Support\Numerosis;
use Nvade\Numerosis\Tests\TestCase;
use PDO;
use Pdo\Mysql;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Database\Models\Domain as StanclDomain;
use Stancl\Tenancy\Database\Models\Tenant as StanclTenant;

/**
 * `HostConfig::apply()` runs from a `booting()` callback registered first
 * thing in `NumerosisServiceProvider::packageRegistered()` — see its own
 * docblock. `TestCase::getEnvironmentSetUp()` already sets every key it
 * normalizes explicitly (it stands in for a real host, same reasoning as
 * `NumerosisServiceProviderDefaultsTest`), so none of this fires in the rest
 * of the suite. Each test below resets one key to "unset" or "stale
 * upstream default" and calls `HostConfig::apply()` directly to prove the
 * normalization actually fires, plus a paired test proving a host's own
 * value survives untouched.
 */
class HostConfigTest extends TestCase
{
    public function test_it_defaults_tenancy_models_when_unset_or_still_stancls_stock_class(): void
    {
        Config::set('tenancy.tenant_model', StanclTenant::class);
        Config::set('tenancy.domain_model', StanclDomain::class);
        Config::set('tenancy.central_user_model');
        Config::set('tenancy.tenant_user_model');

        $this->rebootPackage();

        $this->assertSame(Numerosis::model(Tenant::class), Config::get('tenancy.tenant_model'));
        $this->assertSame(Numerosis::model(Domain::class), Config::get('tenancy.domain_model'));
        $this->assertSame(Numerosis::model(CentralUser::class), Config::get('tenancy.central_user_model'));
        $this->assertSame(Numerosis::model(TenantUser::class), Config::get('tenancy.tenant_user_model'));
    }

    public function test_it_does_not_override_a_hosts_tenancy_models(): void
    {
        // A real, autoloadable class rather than a fake namespace: TestCase's
        // own teardown queries through whatever `tenancy.tenant_model`
        // resolves to, so a nonexistent class here would crash cleanup
        // rather than the assertion below.
        Config::set('tenancy.tenant_model', Tenant::class);

        $this->rebootPackage();

        $this->assertSame(Tenant::class, Config::get('tenancy.tenant_model'));
    }

    public function test_it_defaults_central_domains_when_empty(): void
    {
        Config::set('tenancy.central_domains', []);

        $this->rebootPackage();

        $this->assertSame([Config::string('numerosis.domains.central')], Config::get('tenancy.central_domains'));
    }

    public function test_it_does_not_override_existing_central_domains(): void
    {
        Config::set('tenancy.central_domains', ['custom.test']);

        $this->rebootPackage();

        $this->assertSame(['custom.test'], Config::get('tenancy.central_domains'));
    }

    /**
     * Stancl's own stock default is `['127.0.0.1', 'localhost']`, not `[]` —
     * found by `tests/Feature/FreshHostTest.php`, which is the first test in
     * this suite that never overrides `tenancy.central_domains` itself and
     * so is the first to actually reach this branch of `apply()`.
     */
    public function test_it_defaults_central_domains_when_still_stancls_stock_value(): void
    {
        Config::set('tenancy.central_domains', ['127.0.0.1', 'localhost']);

        $this->rebootPackage();

        $this->assertSame([Config::string('numerosis.domains.central')], Config::get('tenancy.central_domains'));
    }

    public function test_it_appends_missing_tenancy_bootstrappers(): void
    {
        Config::set('tenancy.bootstrappers', [DatabaseTenancyBootstrapper::class]);

        $this->rebootPackage();

        $bootstrappers = Config::array('tenancy.bootstrappers');

        $this->assertSame([
            DatabaseTenancyBootstrapper::class,
            SpatiePermissionsBootstrapper::class,
            AuthGuardBootstrapper::class,
        ], $bootstrappers);
    }

    public function test_it_does_not_duplicate_bootstrappers_already_present(): void
    {
        Config::set('tenancy.bootstrappers', [
            SpatiePermissionsBootstrapper::class,
            AuthGuardBootstrapper::class,
        ]);

        $this->rebootPackage();

        $this->assertSame([
            SpatiePermissionsBootstrapper::class,
            AuthGuardBootstrapper::class,
        ], Config::array('tenancy.bootstrappers'));
        $this->assertNotContains('tenancy.bootstrappers', HostConfig::applied());
    }

    public function test_it_appends_the_vendor_tenant_migration_path(): void
    {
        Config::set('tenancy.migration_parameters', [
            '--force' => true,
            '--path' => [database_path('migrations/tenant')],
            '--realpath' => true,
        ]);

        $this->rebootPackage();

        /** @var list<string> $paths */
        $paths = Config::array('tenancy.migration_parameters')['--path'];

        $this->assertContains(database_path('migrations/tenant'), $paths);
        $this->assertContains(Numerosis::tenantMigrationPath(), $paths);
    }

    public function test_it_forces_realpath_true_for_migration_parameters(): void
    {
        Config::set('tenancy.migration_parameters', [
            '--path' => [Numerosis::tenantMigrationPath()],
            '--realpath' => false,
        ]);

        $this->rebootPackage();

        $this->assertTrue(Config::array('tenancy.migration_parameters')['--realpath']);
    }

    public function test_it_does_not_touch_already_normalized_migration_parameters(): void
    {
        Config::set('tenancy.migration_parameters', [
            '--path' => [Numerosis::tenantMigrationPath()],
            '--realpath' => true,
        ]);

        $this->rebootPackage();

        $this->assertNotContains('tenancy.migration_parameters', HostConfig::applied());
    }

    public function test_it_defaults_tenant_seeder_when_unset_or_still_stancls_stock_class(): void
    {
        Config::set('tenancy.seeder_parameters', ['--class' => 'DatabaseSeeder']);

        $this->rebootPackage();

        $this->assertSame(
            \Nvade\Numerosis\Database\Seeders\TenantDatabaseSeeder::class,
            Config::array('tenancy.seeder_parameters')['--class'],
        );
    }

    public function test_it_does_not_override_a_hosts_custom_seeder(): void
    {
        Config::set('tenancy.seeder_parameters', ['--class' => 'App\\Custom\\Seeder']);

        $this->rebootPackage();

        $this->assertSame('App\\Custom\\Seeder', Config::array('tenancy.seeder_parameters')['--class']);
    }

    public function test_it_strips_livewire_from_tenancy_filesystem_disks(): void
    {
        Config::set('tenancy.filesystem.disks', ['local', 'public', 'livewire']);

        $this->rebootPackage();

        $this->assertSame(['local', 'public'], Config::array('tenancy.filesystem.disks'));
    }

    public function test_it_defaults_filesystem_root_override_when_still_stale(): void
    {
        Config::set('tenancy.filesystem.root_override.local', '%storage_path%/app/');

        $this->rebootPackage();

        $this->assertSame('%storage_path%/app/private/', Config::get('tenancy.filesystem.root_override.local'));
    }

    public function test_it_does_not_override_a_hosts_custom_root_override(): void
    {
        Config::set('tenancy.filesystem.root_override.local', '/custom/path/');

        $this->rebootPackage();

        $this->assertSame('/custom/path/', Config::get('tenancy.filesystem.root_override.local'));
    }

    public function test_it_defaults_tenancy_central_connection_when_riding_database_default(): void
    {
        Config::set('database.default', 'mysql');
        Config::set('tenancy.database.central_connection', 'mysql');

        $this->rebootPackage();

        $this->assertSame('central', Config::get('tenancy.database.central_connection'));
    }

    public function test_it_does_not_override_a_deliberately_named_central_connection(): void
    {
        // 'tenant' rather than an unconfigured name: TestCase's teardown
        // queries through whatever this key resolves to, and it must stay a
        // real, connectable connection for that cleanup to succeed.
        Config::set('tenancy.database.central_connection', 'tenant');

        $this->rebootPackage();

        $this->assertSame('tenant', Config::get('tenancy.database.central_connection'));
    }

    public function test_it_clones_the_default_connection_into_central_when_absent(): void
    {
        $mysql = Config::array('database.connections.mysql');

        Config::set('database.connections', ['mysql' => $mysql]);
        Config::set('database.default', 'mysql');

        $this->rebootPackage();

        $this->assertSame($mysql, Config::get('database.connections.central'));
    }

    public function test_it_does_not_override_an_existing_central_connection(): void
    {
        Config::set('database.connections.central.marker', 'host-owned');

        $this->rebootPackage();

        $this->assertSame('host-owned', Config::get('database.connections.central.marker'));
    }

    public function test_it_mirrors_innodb_lock_wait_timeout_when_only_lock_wait_timeout_set(): void
    {
        $initCommandKey = PHP_VERSION_ID >= 80500 ? Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND;

        Config::set('database.connections.central.driver', 'mysql');
        Config::set('database.connections.central.options', [
            $initCommandKey => 'SET SESSION lock_wait_timeout = 15',
        ]);

        $this->rebootPackage();

        $options = Config::array('database.connections.central.options');

        $this->assertSame(
            'SET SESSION lock_wait_timeout = 15, innodb_lock_wait_timeout = 15',
            $options[$initCommandKey],
        );
    }

    public function test_it_does_not_touch_options_that_already_set_both_timeouts(): void
    {
        $this->rebootPackage();

        $this->assertNotContains('database.connections', HostConfig::applied());
    }

    public function test_it_defaults_session_domain_when_null(): void
    {
        Config::set('session.domain');

        $this->rebootPackage();

        $this->assertSame('.'.Config::string('numerosis.domains.apex'), Config::get('session.domain'));
    }

    public function test_it_does_not_override_a_hosts_session_domain(): void
    {
        Config::set('session.domain', '.custom.test');

        $this->rebootPackage();

        $this->assertSame('.custom.test', Config::get('session.domain'));
    }

    public function test_it_defaults_failed_jobs_connection_when_riding_database_default(): void
    {
        Config::set('database.default', 'mysql');
        Config::set('queue.failed.database', 'mysql');

        $this->rebootPackage();

        $this->assertSame('central', Config::get('queue.failed.database'));
    }

    public function test_it_does_not_override_a_deliberately_named_failed_jobs_connection(): void
    {
        Config::set('queue.failed.database', 'reporting');

        $this->rebootPackage();

        $this->assertSame('reporting', Config::get('queue.failed.database'));
    }

    public function test_it_defaults_tenant_auth_guard_and_provider_when_absent(): void
    {
        Config::set('auth.guards.tenant');
        Config::set('auth.providers.tenant');

        $this->rebootPackage();

        $this->assertSame([
            'driver' => 'session',
            'provider' => 'tenant',
        ], Config::get('auth.guards.tenant'));

        $this->assertSame([
            'driver' => 'eloquent',
            'model' => Numerosis::model(TenantUser::class),
        ], Config::get('auth.providers.tenant'));
    }

    public function test_it_does_not_override_a_hosts_tenant_guard_or_provider(): void
    {
        Config::set('auth.guards.tenant', ['driver' => 'session', 'provider' => 'tenant_users']);
        Config::set('auth.providers.tenant', ['driver' => 'eloquent', 'model' => 'App\\Custom\\TenantUser']);

        $this->rebootPackage();

        $this->assertSame('tenant_users', Config::get('auth.guards.tenant.provider'));
        $this->assertSame('App\\Custom\\TenantUser', Config::get('auth.providers.tenant.model'));
    }

    public function test_it_defaults_the_central_auth_provider_model_when_invalid(): void
    {
        Config::set('auth.providers.users.model', GenericUser::class);

        $this->rebootPackage();

        $this->assertSame(Numerosis::model(CentralUser::class), Config::get('auth.providers.users.model'));
    }

    public function test_it_does_not_override_a_valid_central_auth_provider_model(): void
    {
        $this->rebootPackage();

        $this->assertNotContains('auth.providers.users.model', HostConfig::applied());
    }

    public function test_it_defaults_auth_password_broker_when_absent(): void
    {
        Config::set('auth.defaults.passwords', 'users');
        Config::set('auth.passwords.users');

        $this->rebootPackage();

        $this->assertSame([
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ], Config::get('auth.passwords.users'));
    }

    public function test_it_does_not_override_a_hosts_password_broker(): void
    {
        $this->rebootPackage();

        $this->assertNotContains('auth.passwords.users', HostConfig::applied());
    }

    public function test_it_fills_missing_keys_in_a_partially_overridden_numerosis_section(): void
    {
        Config::set('numerosis.modules', ['catalogue' => ['fake' => true]]);

        $this->rebootPackage();

        $this->assertSame(['fake' => true], Config::get('numerosis.modules.catalogue'));
        $this->assertSame([], Config::get('numerosis.modules.plugins'));
    }

    public function test_it_leaves_a_hosts_list_shaped_override_untouched(): void
    {
        Config::set('numerosis.features', []);

        $this->rebootPackage();

        $this->assertSame([], Config::get('numerosis.features'));
    }

    public function test_it_applies_nothing_on_a_second_run_against_already_normalized_config(): void
    {
        $this->rebootPackage();

        $this->assertSame([], HostConfig::applied());
    }

    /**
     * spatie/laravel-activitylog's own stock config (the version this
     * package requires) defines no `table_name` key at all — found by
     * `tests/Feature/FreshHostTest.php`, the first test in this suite that
     * doesn't hand-set `activitylog.table_name` (`Tests\TestCase` does, and
     * so does thin-app's stale published `config/activitylog.php`). Left
     * unset, `database/migrations/central/*_create_activity_log_table.php`
     * dies with `Incorrect table name ''` — a null config value
     * interpolates to an empty string in the generated SQL.
     */
    public function test_it_defaults_the_activity_log_table_name_when_unset(): void
    {
        Config::set('activitylog.table_name');

        $this->rebootPackage();

        $this->assertSame('activity_log', Config::get('activitylog.table_name'));
    }

    public function test_it_does_not_override_a_hosts_activity_log_table_name(): void
    {
        Config::set('activitylog.table_name', 'custom_activity_log');

        $this->rebootPackage();

        $this->assertSame('custom_activity_log', Config::get('activitylog.table_name'));
    }

    /**
     * torann/geoip's own stock config ships `service => null`, and
     * `GeoIP::getService()` throws `No GeoIP service is configured.` on
     * that rather than degrading — so an unset key would fatal every
     * checkout page load through `ResolveCheckoutRegion`, not merely lose
     * the region-specific payment-method order.
     */
    public function test_it_defaults_the_geoip_service_when_unset(): void
    {
        Config::set('geoip.service');

        $this->rebootPackage();

        $this->assertSame('maxmind_database', Config::get('geoip.service'));
    }

    public function test_it_does_not_override_a_hosts_geoip_service(): void
    {
        Config::set('geoip.service', 'maxmind_api');

        $this->rebootPackage();

        $this->assertSame('maxmind_api', Config::get('geoip.service'));
    }

    /**
     * `HostConfig::apply()` no longer runs inline from `packageRegistered()`
     * — it's deferred to a `booting()` callback (see that method's own
     * docblock for why: `Stancl\Tenancy\TenancyServiceProvider::register()`
     * frequently hasn't run yet at `packageRegistered()` time, which used to
     * corrupt `tenancy.database`/`tenancy.filesystem`). Calling it directly
     * here is what actually re-runs the normalization this file tests;
     * calling `packageRegistered()` itself would no longer touch it at all.
     */
    private function rebootPackage(): void
    {
        HostConfig::apply();
    }
}
