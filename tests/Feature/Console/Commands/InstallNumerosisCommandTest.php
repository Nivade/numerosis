<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Console\Commands;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Laravel\Fortify\Features as FortifyFeatures;
use Nvade\Numerosis\Boot\Assets;
use Nvade\Numerosis\Database\Seeders\DatabaseSeeder;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Services\Tenancy\AuthGuardBootstrapper;
use Nvade\Numerosis\Tests\TestCase;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use stdClass;

/**
 * `--verify-only` is what makes this testable: the default run publishes
 * files and appends to the host's `.env`, neither of which belongs in a
 * test process.
 *
 * post-extraction-review.md Phase 4.2: one failure-path test per
 * `verify*()` method, each unsetting or corrupting exactly the key that
 * method reads and asserting the command names it. Model-override and
 * seeded-data checks were already covered before this pass; the rest were
 * not, per that plan's own reasoning — a `verify*()` reading a mistyped key
 * passes silently forever, and `HostRequirementsTest`'s doc↔method parity
 * check cannot catch that, only that the method and a doc row both exist.
 *
 * Every test below carries an `@verifies` tag naming the `verify*()` method
 * it exercises — `HostRequirementsTest::test_every_verify_method_has_a_failure_path_test()`
 * greps for these tags and asserts every method on `InstallNumerosisCommand`
 * is named by at least one, so a new `verify*()` with no test here fails
 * that assertion rather than silently shipping untested.
 */
class InstallNumerosisCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Most tests here are about configuration, and would otherwise all
        // fail on verifyCentralDataSeeded() — which is exactly the point of
        // that check, so the two seeded-data tests below turn it off and on
        // deliberately rather than this being papered over.
        $this->seedCentralData();
    }

    public function test_it_passes_when_every_model_override_names_a_real_subclass(): void
    {
        $this->install()->assertSuccessful();
    }

    /**
     * @verifies verifyCentralDataSeeded
     *
     * The gap this closes: thin-app's own `db:seed` runs Laravel's skeleton
     * seeder, so the package's seeders were never reached and the central
     * database sat at zero permissions and zero plans while every other check
     * in this command passed.
     */
    public function test_it_fails_when_the_central_permissions_table_is_empty(): void
    {
        DB::connection('central')->table('permissions')->delete();

        $this->install()
            ->expectsOutputToContain('central `permissions` table is empty')
            ->assertFailed();
    }

    /** @verifies verifyCentralDataSeeded */
    public function test_it_fails_when_no_payment_plan_has_been_seeded(): void
    {
        DB::connection('central')->table('payment_plan_features')->delete();
        DB::connection('central')->table('payment_plans')->delete();

        $this->install()
            ->expectsOutputToContain('central `payment_plans` table is empty')
            ->assertFailed();
    }

    /**
     * `numerosis:install` (seeds by default) is the fix the failures above
     * point at, so it has to survive being run against an already-seeded
     * database — an install command nobody can re-run is one nobody runs at
     * all.
     */
    public function test_seeding_is_idempotent(): void
    {
        $before = [
            'permissions' => DB::connection('central')->table('permissions')->count(),
            'payment_plans' => DB::connection('central')->table('payment_plans')->count(),
            'features' => DB::connection('central')->table('features')->count(),
            'payment_plan_features' => DB::connection('central')->table('payment_plan_features')->count(),
        ];

        $this->seedCentralData();

        foreach ($before as $table => $count) {
            $this->assertSame(
                $count,
                DB::connection('central')->table($table)->count(),
                "Re-seeding duplicated rows in `{$table}`.",
            );
        }
    }

    /**
     * Not `$this->seed(DatabaseSeeder::class)`: Testbench's `seed()` goes
     * through `artisan('db:seed')`, and in an app with stancl/tenancy
     * installed that name resolves to `Stancl\Tenancy\Commands\Seed`, which
     * throws `The "tenants" option does not exist` — see
     * `InstallNumerosisCommand::seedCentralData()`'s docblock and
     * `.ai/rules/tenant-provisioning.md`. Any test in this package that
     * wants to seed has the same problem.
     */
    private function seedCentralData(): void
    {
        Model::unguarded(function (): void {
            resolve(DatabaseSeeder::class)->setContainer(app())->__invoke();
        });
    }

    /** @verifies verifyModelOverrides */
    public function test_it_fails_when_a_model_override_names_a_class_that_does_not_exist(): void
    {
        config()->set('numerosis.models.'.Tenant::class, 'App\\Models\\Central\\NoSuchTenant');

        $this->install()
            ->expectsOutputToContain('does not exist')
            ->assertFailed();
    }

    /** @verifies verifyModelOverrides */
    public function test_it_fails_when_a_model_override_is_not_a_subclass_of_the_package_model(): void
    {
        config()->set('numerosis.models.'.Tenant::class, stdClass::class);

        $this->install()
            ->expectsOutputToContain('does not extend')
            ->assertFailed();
    }

    /**
     * @verifies verifyModelOverrides
     *
     * The supported no-stub shape (D8): no override, no published stub, so
     * package code runs on the package's own models and nothing is wrong.
     */
    public function test_it_passes_when_no_override_is_set_and_no_stub_is_published(): void
    {
        config()->set('numerosis.models', []);

        $this->install()->assertSuccessful();
    }

    /**
     * @verifies verifyTenancyModels
     *
     * Restores the real class before returning — `TestCase`'s own teardown
     * (`deleteTenantDatabases()`) resolves the tenant-model config key
     * to query and clean up tenant rows, so leaving the bogus value in place
     * for the rest of the test crashes teardown instead of just this test.
     */
    public function test_it_fails_when_tenancy_tenant_model_does_not_resolve_to_a_real_class(): void
    {
        $key = 'tenancy.tenant_model';
        $real = config($key);
        Config::set('tenancy.tenant_model', 'App\\Models\\Central\\NoSuchTenant');

        try {
            $this->install()
                ->expectsOutputToContain("config('{$key}') must name a class that exists")
                ->assertFailed();
        } finally {
            Config::set('tenancy.tenant_model', $real);
        }
    }

    /** @verifies verifyTenancyModels */
    public function test_it_fails_when_the_tenant_seeder_class_does_not_exist(): void
    {
        config()->set('tenancy.seeder_parameters', ['--class' => 'App\\Database\\Seeders\\NoSuchSeeder']);

        $this->install()
            ->expectsOutputToContain("['--class'] names 'App\\Database\\Seeders\\NoSuchSeeder', which does not exist")
            ->assertFailed();
    }

    /** @verifies verifyCentralDomains */
    public function test_it_fails_when_central_domains_is_empty(): void
    {
        Config::set('tenancy.central_domains', []);

        $this->install()
            ->expectsOutputToContain("config('".'tenancy.central_domains'."') must list at least one hostname")
            ->assertFailed();
    }

    /** @verifies verifyLockWaitTimeout */
    public function test_it_fails_when_lock_wait_timeout_is_set_without_the_innodb_variant(): void
    {
        /** @var array<string, mixed> $central */
        $central = config('database.connections.central');
        $central['options'] = ['SET SESSION lock_wait_timeout = 10'];
        config()->set('database.connections.central', $central);

        $this->install()
            ->expectsOutputToContain('sets lock_wait_timeout but not innodb_lock_wait_timeout')
            ->assertFailed();
    }

    /** @verifies verifyDatabaseConnections */
    public function test_it_fails_when_the_central_database_connection_is_missing(): void
    {
        /** @var array<string, mixed> $connections */
        $connections = config('database.connections');
        unset($connections['central']);
        config()->set('database.connections', $connections);

        $this->install()
            ->expectsOutputToContain("config('database.connections.central') is missing")
            ->assertFailed();
    }

    /** @verifies verifyDatabaseConnections */
    public function test_it_fails_when_the_template_tenant_connection_does_not_exist(): void
    {
        config()->set('tenancy.database.template_tenant_connection', 'no_such_connection');

        $this->install()
            ->expectsOutputToContain("names 'no_such_connection', which is not in config('database.connections')")
            ->assertFailed();
    }

    /** @verifies verifySessionDomain */
    public function test_it_fails_when_session_domain_has_no_leading_dot(): void
    {
        config()->set('session.domain', 'numerosistest.test');

        $this->install()
            ->expectsOutputToContain("config('session.domain') must start with a leading dot")
            ->assertFailed();
    }

    /** @verifies verifyAuthGuards */
    public function test_it_fails_when_a_numerosis_auth_guard_does_not_resolve(): void
    {
        config()->set('numerosis.auth.guards.central', 'no_such_guard');

        $this->install()
            ->expectsOutputToContain("config('numerosis.auth.guards.central') does not resolve to a real guard")
            ->assertFailed();
    }

    /** @verifies verifyAuthPasswordBroker */
    public function test_it_fails_when_the_default_password_broker_is_unset(): void
    {
        config()->set('auth.defaults.passwords');

        $this->install()
            ->expectsOutputToContain("config('auth.defaults.passwords') is unset")
            ->assertFailed();
    }

    /** @verifies verifySocialRoutes */
    public function test_it_fails_when_a_social_route_name_is_empty(): void
    {
        config()->set('numerosis.social.routes.redirect.name', '');

        $this->install()
            ->expectsOutputToContain("config('numerosis.social.routes.redirect.name') must name a route")
            ->assertFailed();
    }

    /** @verifies verifyFailedJobsConnection */
    public function test_it_fails_when_the_failed_jobs_connection_is_unset(): void
    {
        config()->set('queue.failed.database', '');

        $this->install()
            ->expectsOutputToContain("config('queue.failed.database') must name a database connection")
            ->assertFailed();
    }

    /** @verifies verifyLivewireUploadDisk */
    public function test_it_fails_when_the_livewire_upload_disk_is_tenant_suffixed(): void
    {
        config()->set('livewire.temporary_file_upload.disk', 'local');

        $this->install()
            ->expectsOutputToContain("which config('tenancy.filesystem.disks') tenant-suffixes")
            ->assertFailed();
    }

    /** @verifies verifyLivewireComponentNamespaces */
    public function test_it_fails_when_a_livewire_component_namespace_points_at_a_missing_directory(): void
    {
        config()->set('livewire.component_namespaces.numerosis-layouts', '/no/such/directory');

        $this->install()
            ->expectsOutputToContain("config('livewire.component_namespaces.numerosis-layouts') must point at an existing directory")
            ->assertFailed();
    }

    /** @verifies verifyDomainConfig */
    public function test_it_fails_when_numerosis_domains_apex_is_empty(): void
    {
        config()->set('numerosis.domains.apex', '');

        $this->install()
            ->expectsOutputToContain("config('numerosis.domains.apex') is unset")
            ->assertFailed();
    }

    /** @verifies verifyDomainConfig */
    public function test_it_fails_when_the_tenant_pattern_is_missing_the_tenant_placeholder(): void
    {
        config()->set('numerosis.domains.tenant_pattern', 'central.numerosistest.test');

        $this->install()
            ->expectsOutputToContain("must contain the literal '{tenant}' placeholder")
            ->assertFailed();
    }

    /** @verifies verifyTenantMigrationPath */
    public function test_it_fails_when_a_tenant_migration_path_entry_is_not_a_string(): void
    {
        /** @var array{'--path': list<string>} $parameters */
        $parameters = config('tenancy.migration_parameters');
        /** @var list<mixed> $paths */
        $paths = [...$parameters['--path'], 123];
        config()->set('tenancy.migration_parameters.--path', $paths);

        $this->install()
            ->expectsOutputToContain("['--path'] contains a non-string entry")
            ->assertFailed();
    }

    /** @verifies verifyTenantMigrationPath */
    public function test_it_fails_when_a_tenant_migration_path_entry_is_not_absolute(): void
    {
        /** @var array{'--path': list<string>} $parameters */
        $parameters = config('tenancy.migration_parameters');
        config()->set('tenancy.migration_parameters.--path', [...$parameters['--path'], 'relative/path']);

        $this->install()
            ->expectsOutputToContain("['--path'] must be absolute, got 'relative/path'")
            ->assertFailed();
    }

    /**
     * @verifies verifyPublishedAssetsMatchSource
     *
     * Warns, doesn't fail — the one check in this command that doesn't. A
     * host is allowed to customise a published copy; this only exists so a
     * *silent* drift on payment-critical JS gets noticed. `resource_path()`
     * in this harness resolves under `vendor/orchestra/testbench-core/`, not
     * this package's own `resources/` — genuinely a different path, so
     * writing a diverged file there is a real test of the hash comparison,
     * not comparing a file to itself.
     */
    public function test_it_warns_without_failing_when_a_published_asset_diverges_from_source(): void
    {
        $target = resource_path('css');
        File::ensureDirectoryExists($target);
        File::put($target.'/tokens.css', '/* intentionally diverged */');

        try {
            $this->install()
                ->expectsOutputToContain('Published assets differ from the package originals')
                ->assertSuccessful();
        } finally {
            File::delete($target.'/tokens.css');
        }
    }

    /**
     * @verifies verifyPublishedAssetsMatchSource
     *
     * Regression: `app.css`/`app.js` are the two names every fresh Laravel
     * skeleton already ships under (a bare `//` for `app.js`), so before the
     * fingerprint check this warned on *every* install that never published
     * `numerosis-assets` at all — comparing a host's own untouched default
     * against an unrelated package file with the same relative path. Only a
     * target that still carries something a genuinely published-then-edited
     * copy would (the `tokens.css` import, the `livewire-hot-reload` import)
     * should count as drift.
     */
    public function test_it_says_nothing_when_the_hosts_own_app_assets_never_came_from_the_package(): void
    {
        $css = resource_path('css');
        $js = resource_path('js');
        File::ensureDirectoryExists($css);
        File::ensureDirectoryExists($js);
        File::put($css.'/app.css', "@import 'tailwindcss';\n");
        File::put($js.'/app.js', "//\n");

        try {
            $this->install()
                ->doesntExpectOutputToContain('Published assets differ from the package originals')
                ->assertSuccessful();
        } finally {
            File::delete($css.'/app.css');
            File::delete($js.'/app.js');
        }
    }

    /**
     * @verifies verifyCentralMigrationCollisions
     *
     * Warns rather than fails, like the published-asset check: a host may
     * legitimately have merged the package's schema into its own file. The
     * silence is what this closes — Laravel's migrator keys migrations by
     * filename across every registered path, and `database/migrations` is
     * appended last, so the host's copy wins and the package's never runs,
     * with no error at any point.
     */
    public function test_it_warns_when_a_host_migration_collides_with_a_package_central_migration(): void
    {
        $collision = database_path('migrations/2019_09_01_000000_create_users_table.php');
        File::ensureDirectoryExists(dirname($collision));
        File::put($collision, '<?php'."\n\n// package central migration, kept by accident\n");

        try {
            $this->install()
                ->expectsOutputToContain('2019_09_01_000000_create_users_table.php')
                ->assertSuccessful();
        } finally {
            File::delete($collision);
        }
    }

    /** @verifies verifyCentralMigrationCollisions */
    public function test_it_says_nothing_when_no_host_migration_shares_a_package_filename(): void
    {
        $ownMigration = database_path('migrations/2026_08_13_000000_create_host_widgets_table.php');
        File::ensureDirectoryExists(dirname($ownMigration));
        File::put($ownMigration, '<?php'."\n\n// a migration only this host has\n");

        try {
            $this->install()
                ->doesntExpectOutputToContain('share a filename')
                ->assertSuccessful();
        } finally {
            File::delete($ownMigration);
        }
    }

    /**
     * @verifies verifyTenantResolverCache
     *
     * Warns rather than fails: the resolver cache being off is a cost, not a
     * break. `$shouldCache` is a static the provider sets from a `booting()`
     * callback, so it is set here directly — what this test is about is the
     * command reporting the state, not how the provider arrived at it (that
     * is `TenantResolverCacheTest`'s job).
     */
    public function test_it_warns_when_the_tenant_resolver_cache_is_off(): void
    {
        $original = DomainTenantResolver::$shouldCache;
        DomainTenantResolver::$shouldCache = false;
        Config::set('cache.serializable_classes', false);

        try {
            $this->install()
                ->expectsOutputToContain('resolver cache is disabled')
                ->assertSuccessful();
        } finally {
            DomainTenantResolver::$shouldCache = $original;
        }
    }

    /** @verifies verifyTenantResolverCache */
    public function test_it_says_nothing_about_the_resolver_cache_when_a_host_turned_it_off_deliberately(): void
    {
        $original = DomainTenantResolver::$shouldCache;
        DomainTenantResolver::$shouldCache = false;
        Config::set('numerosis.tenancy.cache_resolved_tenants', false);

        try {
            $this->install()
                ->doesntExpectOutputToContain('resolver cache is disabled')
                ->assertSuccessful();
        } finally {
            DomainTenantResolver::$shouldCache = $original;
        }
    }

    /** @verifies verifyStripeKeys */
    public function test_it_fails_when_a_stripe_key_is_unset(): void
    {
        config()->set('cashier.key', '');

        $this->install()
            ->expectsOutputToContain("config('cashier.key') is not set")
            ->assertFailed();
    }

    /**
     * @verifies verifyPublicAssets
     *
     * `verifyPublicAssets()` gates its whole check on
     * `public/vendor/numerosis` existing at all — the signal that
     * `vendor:publish --tag=numerosis-public-assets` has run at least once.
     * Nothing in the Workbench harness creates that directory, so this test
     * creates it (with only one of the two bundles in it) to reach the
     * branch it is testing.
     */
    public function test_it_fails_when_only_half_the_prebuilt_public_assets_landed(): void
    {
        $paths = Assets::publishedPaths();

        try {
            File::ensureDirectoryExists(dirname($paths['css']));
            File::put($paths['css'], '/* published */');

            $this->install()
                ->expectsOutputToContain('public/vendor/numerosis/numerosis.js does not')
                ->assertFailed();
        } finally {
            File::deleteDirectory(public_path('vendor'));
        }
    }

    /**
     * @verifies verifyPublicAssets
     *
     * The other half: with both bundles present the check is silent.
     * Asserting only the failing branch would pass just as well against a
     * check that can never succeed.
     */
    public function test_it_passes_the_public_asset_check_once_both_bundles_are_published(): void
    {
        try {
            foreach (Assets::publishedPaths() as $path) {
                File::ensureDirectoryExists(dirname($path));
                File::put($path, '/* published */');
            }

            $this->install()->doesntExpectOutputToContain('does not — run `php artisan vendor:publish --tag=numerosis-public-assets --force`');
        } finally {
            File::deleteDirectory(public_path('vendor'));
        }
    }

    /**
     * torann/geoip went in Phase 6 of
     * `.claude/plans/archive/humming-nibbling-flame.md`, and with it the manual
     * MaxMind licence step this command used to print. Asserted rather than
     * simply deleted: the step was conditional on `geoip.service`, so its
     * removal is invisible in any run that did not set that key.
     */
    public function test_it_no_longer_names_the_maxmind_step(): void
    {
        $this->install()
            ->doesntExpectOutputToContain('MAXMIND_LICENSE_KEY')
            ->assertSuccessful();
    }

    /** @verifies verifyTenancyBootstrappers */
    public function test_it_fails_when_a_package_bootstrapper_is_missing(): void
    {
        /** @var list<string> $bootstrappers */
        $bootstrappers = Config::array('tenancy.bootstrappers');

        Config::set('tenancy.bootstrappers', array_values(array_diff(
            $bootstrappers,
            [AuthGuardBootstrapper::class],
        )));

        $this->install()
            ->expectsOutputToContain('tenancy.bootstrappers')
            ->assertFailed();
    }

    /** @verifies verifyTenantAuthProvider */
    public function test_it_fails_when_the_tenant_provider_names_no_class(): void
    {
        Config::set('auth.providers.tenant.model', 'App\Models\NotAClass');

        $this->install()
            ->expectsOutputToContain('auth.providers.tenant.model')
            ->assertFailed();
    }

    /** @verifies verifyTenantAuthProvider */
    public function test_it_fails_when_the_tenant_password_broker_is_missing(): void
    {
        Config::set('auth.passwords.tenant');

        $this->install()
            ->expectsOutputToContain('auth.passwords.tenant')
            ->assertFailed();
    }

    /** @verifies verifyActivityLogTable */
    public function test_it_fails_when_the_activity_log_table_does_not_exist(): void
    {
        Config::set('activitylog.table_name', 'no_such_activity_table');

        $this->install()
            ->expectsOutputToContain('no_such_activity_table')
            ->assertFailed();
    }

    /** @verifies verifyTenantFilesystemRoot */
    public function test_it_fails_when_the_tenant_filesystem_root_drops_the_placeholder(): void
    {
        Config::set('tenancy.filesystem.root_override.local', '/var/www/storage/app/private/');

        $this->install()
            ->expectsOutputToContain('%storage_path%')
            ->assertFailed();
    }

    /** @verifies verifyFortifyFeatures */
    public function test_it_fails_when_fortify_enables_a_feature_with_no_views(): void
    {
        Config::set('numerosis.auth.manage_fortify_features', false);
        Config::set('fortify.features', [FortifyFeatures::twoFactorAuthentication()]);

        $this->install()
            ->expectsOutputToContain('two-factor-authentication')
            ->assertFailed();
    }

    /**
     * `artisan()` is typed `PendingCommand|int` — it returns the int only once
     * expectations have been run. Narrowing here keeps every test a single
     * chained call without a baseline entry.
     */
    /**
     * Presence was checked and the driver was not, so a host on a driver this
     * package never runs on passed install and then failed five queued jobs
     * into its first provision.
     */
    public function test_it_fails_when_the_central_connection_names_an_unsupported_driver(): void
    {
        $this->withCentralDriver('sqlsrv', function (): void {
            $this->install()
                ->expectsOutputToContain("driver') is 'sqlsrv'")
                ->assertFailed();
        });
    }

    /**
     * SQLite runs, so refusing the install would be wrong; its one writer per
     * file is a property of the deployment rather than a misconfiguration.
     */
    public function test_it_warns_without_failing_when_the_central_connection_is_sqlite(): void
    {
        $this->withCentralDriver('sqlite', function (): void {
            $this->install()
                ->expectsOutputToContain('one writer per database file')
                ->assertSuccessful();
        });
    }

    public function test_it_accepts_postgresql_without_a_warning(): void
    {
        $this->withCentralDriver('pgsql', function (): void {
            $this->install()
                ->doesntExpectOutputToContain('one writer per database file')
                ->assertSuccessful();
        });
    }

    private function withCentralDriver(string $driver, callable $assertions): void
    {
        $original = Config::string('database.connections.central.driver');

        try {
            Config::set('database.connections.central.driver', $driver);

            $assertions();
        } finally {
            Config::set('database.connections.central.driver', $original);
        }
    }

    private function install(): PendingCommand
    {
        $command = $this->artisan('numerosis:install', ['--verify-only' => true]);

        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
