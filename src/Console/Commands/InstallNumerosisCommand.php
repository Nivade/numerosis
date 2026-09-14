<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Console\Commands;

use Composer\Autoload\ClassLoader;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Features as FortifyFeatures;
use Nvade\Numerosis\Boot\Assets;
use Nvade\Numerosis\Boot\HostConfig;
use Nvade\Numerosis\Database\Seeders\DatabaseSeeder;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\Services\Tenancy\AuthGuardBootstrapper;
use Nvade\Numerosis\Services\Tenancy\PasswordBrokerBootstrapper;
use Nvade\Numerosis\Services\Tenancy\PreservingPathTenantResolver;
use Nvade\Numerosis\Services\Tenancy\SpatiePermissionsBootstrapper;
use ReflectionProperty;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;

/**
 * Publishes config and model stubs, appends missing `.env` keys, seeds central
 * data, then verifies the result. The verification is the point: what it
 * catches otherwise surfaces as a 404, a 500 or a mimetype rejection naming
 * neither the config key nor the cause. Re-runnable with `--verify-only`.
 */
class InstallNumerosisCommand extends Command
{
    public $signature = 'numerosis:install
                        {--verify-only : Run the host-configuration checks without publishing anything or touching .env}
                        {--no-seed : Skip the package\'s central seeders (roles/permissions and example plans) — run by default}';

    public $description = 'Publish Numerosis config and model stubs, then verify the host is wired correctly';

    /**
     * Every config key {@see HostConfig} can write,
     * mapped to the check that covers it. `auth.passwords.*` is listed by
     * prefix, since the default broker's name is the host's to choose.
     */
    public const array VERIFIED_CONFIG_KEYS = [
        'activitylog.table_name' => 'verifyActivityLogTable',
        'auth.guards.tenant' => 'verifyAuthGuards',
        'auth.passwords.' => 'verifyAuthPasswordBroker',
        'auth.providers.tenant' => 'verifyTenantAuthProvider',
        'auth.providers.users.model' => 'verifyTenancyModels',
        'database.connections.central' => 'verifyDatabaseConnections',
        'fortify.features' => 'verifyFortifyFeatures',
        'queue.failed.database' => 'verifyFailedJobsConnection',
        'session.domain' => 'verifySessionDomain',
        'tenancy.bootstrappers' => 'verifyTenancyBootstrappers',
        'tenancy.central_domains' => 'verifyCentralDomains',
        'tenancy.central_user_model' => 'verifyTenancyModels',
        'tenancy.database.central_connection' => 'verifyDatabaseConnections',
        'tenancy.domain_model' => 'verifyTenancyModels',
        'tenancy.filesystem.disks' => 'verifyLivewireUploadDisk',
        'tenancy.filesystem.root_override.local' => 'verifyTenantFilesystemRoot',
        'tenancy.migration_parameters' => 'verifyTenantMigrationPath',
        'tenancy.seeder_parameters' => 'verifyTenancyModels',
        'tenancy.tenant_model' => 'verifyTenancyModels',
        'tenancy.tenant_user_model' => 'verifyTenancyModels',
    ];

    /** @var list<string> */
    private array $failures = [];

    public function handle(): int
    {
        if (! $this->option('verify-only')) {
            $this->publishAssets();
            $this->appendEnvKeys();
        }

        if (! $this->option('no-seed') && ! $this->option('verify-only')) {
            $this->seedCentralData();
        }

        $this->printConfiguredKeys();

        $this->newLine();
        $this->components->info('Verifying host configuration');

        $this->verifyTenancyModels();
        $this->verifyTenancyBootstrappers();
        $this->verifyCentralDomains();
        $this->verifyDatabaseConnections();
        $this->verifyLockWaitTimeout();
        $this->verifySessionDomain();
        $this->verifyAuthGuards();
        $this->verifyAuthPasswordBroker();
        $this->verifyTenantAuthProvider();
        $this->verifyActivityLogTable();
        $this->verifyTenantFilesystemRoot();
        $this->verifyFortifyFeatures();
        $this->verifySocialRoutes();
        $this->verifyFailedJobsConnection();
        $this->verifyLivewireUploadDisk();
        $this->verifyLivewireComponentNamespaces();
        $this->verifyDomainConfig();
        $this->verifyTenantMigrationPath();
        $this->verifyCentralMigrationCollisions();
        $this->verifyTenantResolverCache();
        $this->verifyPublishedAssetsMatchSource();
        $this->verifyPublicAssets();
        $this->verifyStripeKeys();
        $this->verifyModelOverrides();
        $this->verifyCentralDataSeeded();

        if ($this->failures !== []) {
            $this->newLine();
            $this->components->error('numerosis:install found '.count($this->failures).' problem(s):');

            foreach ($this->failures as $failure) {
                $this->line("  - {$failure}");
            }

            $this->newLine();
            $this->components->error('See docs/host-requirements.md for the required shape of each config file above.');

            return self::FAILURE;
        }

        $this->components->info('Host configuration looks correct.');
        $this->printManualSteps();

        return self::SUCCESS;
    }

    /** Lists the config keys {@see HostConfig} filled in for you on boot. */
    private function printConfiguredKeys(): void
    {
        $applied = HostConfig::applied();

        $this->newLine();
        $this->components->info('Configured '.count($applied).' host config key(s) automatically:');

        foreach ($applied as $key) {
            $this->line("  - {$key}");
        }
    }

    private function publishAssets(): void
    {
        $this->call('vendor:publish', ['--tag' => 'numerosis-config', '--force' => false]);
        $this->call('vendor:publish', ['--tag' => 'numerosis-models', '--force' => false]);

        // An empty routes/tenant.php, which Numerosis::routes() loads into
        // the tenant group. routes/web.php ships with every skeleton.
        $this->call('vendor:publish', ['--tag' => 'numerosis-routes', '--force' => false]);

        // Starting templates for resources/css/app.css and resources/js.
        // Safe to delete any copy you don't intend to customize.
        $this->call('vendor:publish', ['--tag' => 'numerosis-assets', '--force' => false]);

        // Tenant migrations are deliberately not published: they run from
        // the package. Publish `numerosis-tenant-migrations` only to edit one.

        // Stubs published above did not exist when HostConfig::apply()
        // resolved these classes at boot, so this cache still answers with
        // the package's own class.
        Numerosis::resetModelCache();

        $this->forgetMissingClasses();
    }

    /**
     * Clears Composer's permanently-missing-class cache, which still holds the
     * stubs `class_exists()` could not find at boot. Reflection is the only
     * way in, and this command is one-shot and off the request path.
     */
    private function forgetMissingClasses(): void
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $property = new ReflectionProperty($loader, 'missingClasses');
            $property->setValue($loader, []);
        }
    }

    /**
     * Seeds roles and permissions and the example payment plans. Safe to
     * re-run: every seeder keys on natural keys.
     *
     * Skip with `--no-seed`, but note that an empty `permissions` table is
     * worse than missing data: Spatie throws where it might have denied, so
     * the first admin-panel request 500s.
     */
    private function seedCentralData(): void
    {
        $this->newLine();
        $this->components->info('Seeding central data');

        Model::unguarded(function (): void {
            $this->laravel->make(DatabaseSeeder::class)
                ->setContainer($this->laravel)
                ->setCommand($this)
                ->__invoke();
        });
    }

    /** Appends missing env keys. Never overwrites one you already set. */
    private function appendEnvKeys(): void
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath)) {
            $this->components->warn('.env not found — skipping env key check.');

            return;
        }

        $env = File::get($envPath);

        // Domains derive from APP_URL, so no domain key is appended here.
        // Set NUMEROSIS_APEX_DOMAIN / NUMEROSIS_CENTRAL_DOMAIN to override.
        $keys = [
            'SESSION_DOMAIN' => 'null',
            'STRIPE_KEY' => 'pk_test_your_stripe_publishable_key',
            'STRIPE_SECRET' => 'sk_test_your_stripe_secret_key',
            'STRIPE_WEBHOOK_SECRET' => 'whsec_your_webhook_secret',
        ];

        $missing = [];

        foreach ($keys as $key => $placeholder) {
            if (preg_match('/^'.preg_quote($key, '/').'=/m', $env) !== 1) {
                $missing[] = "{$key}={$placeholder}";
            }
        }

        if ($missing === []) {
            return;
        }

        File::append($envPath, "\n# Added by numerosis:install — see docs/host-requirements.md\n".implode("\n", $missing)."\n");

        $this->components->info('Appended '.count($missing).' missing env key(s) to .env: '.implode(', ', array_keys($keys)));
    }

    private function verifyTenancyModels(): void
    {
        $keys = [
            'tenancy.tenant_model',
            'tenancy.domain_model',
            'tenancy.central_user_model',
            'tenancy.tenant_user_model',
        ];

        foreach ($keys as $key) {
            $class = Config::get($key);

            if (! is_string($class) || $class === '' || ! class_exists($class)) {
                $this->failures[] = "config('{$key}') must name a class that exists: an unresolvable model answers 404 on every tenant URL rather than reporting a config problem.";
            }
        }

        $parameters = Config::get('tenancy.seeder_parameters');
        $seeder = is_array($parameters) ? ($parameters['--class'] ?? null) : null;

        // An unqualified name resolves under Database\Seeders, same as
        // Laravel's own seed command treats it.
        if (is_string($seeder) && ! str_contains($seeder, '\\')) {
            $seeder = 'Database\\Seeders\\'.$seeder;
        }

        if (is_string($seeder) && ! class_exists($seeder)) {
            $this->failures[] = "config('tenancy.seeder_parameters')['--class'] names '{$seeder}', which does not exist — tenant provisioning then fails inside the queued chain, so the tenant row appears and provisioned_at never gets set.";
        }
    }

    /**
     * The three bootstrappers this package cannot run without. Each failure is
     * silent and looks like something else: the tenant guard resolving central
     * users, permission lookups reading the central tables, and password
     * resets going to the wrong broker.
     */
    private function verifyTenancyBootstrappers(): void
    {
        $bootstrappers = Config::array('tenancy.bootstrappers', []);

        $required = [
            SpatiePermissionsBootstrapper::class => 'permission lookups then read the central roles and permissions inside tenant context',
            AuthGuardBootstrapper::class => 'the tenant guard then resolves against the central users table',
            PasswordBrokerBootstrapper::class => 'password resets in tenant context then use the central broker',
        ];

        foreach ($required as $class => $consequence) {
            if (! in_array($class, $bootstrappers, true)) {
                $this->failures[] = "config('tenancy.bootstrappers') is missing {$class} — {$consequence}.";
            }
        }
    }

    /**
     * The tenant guard's own provider and broker. `verifyAuthPasswordBroker()`
     * only covers whichever broker `auth.defaults.passwords` names, which is
     * the central one.
     */
    private function verifyTenantAuthProvider(): void
    {
        $provider = Config::get('auth.providers.tenant');
        $model = is_array($provider) ? ($provider['model'] ?? null) : null;

        if (! is_string($model) || ! class_exists($model)) {
            $this->failures[] = "config('auth.providers.tenant.model') must name a class that exists — the tenant guard otherwise resolves nobody, and every tenant route redirects to login.";
        }

        $broker = Config::get('auth.passwords.tenant');

        if (! is_array($broker)) {
            $this->failures[] = "config('auth.passwords.tenant') is missing — PasswordBrokerBootstrapper points tenant password resets at it, and an unset broker throws from Password::broker().";
        }
    }

    /**
     * spatie/laravel-activitylog reads its table name from config on every
     * write, so a name with no table behind it fails at the first logged
     * event rather than at boot.
     */
    private function verifyActivityLogTable(): void
    {
        $table = Config::get('activitylog.table_name');

        if (! is_string($table) || $table === '') {
            $this->failures[] = "config('activitylog.table_name') is unset — the activity log writes to a table named from this key.";

            return;
        }

        $connection = Config::string('tenancy.database.central_connection', 'central');

        if (! Schema::connection($connection)->hasTable($table)) {
            $this->failures[] = "config('activitylog.table_name') is '{$table}', which does not exist on the '{$connection}' connection.";
        }
    }

    /**
     * stancl's stock tenant root predates Laravel 11 moving the `local` disk
     * to `storage/app/private`. Pointed at the pre-11 path, every tenant read
     * and write lands one directory above where the disk actually is.
     */
    private function verifyTenantFilesystemRoot(): void
    {
        $override = Config::get('tenancy.filesystem.root_override.local');

        if (! is_string($override) || ! str_contains($override, '%storage_path%')) {
            $this->failures[] = "config('tenancy.filesystem.root_override.local') must contain the '%storage_path%' placeholder — stancl substitutes the tenant's own storage path into it, and a literal path sends every tenant to the same directory.";

            return;
        }

        $root = Config::string('filesystems.disks.local.root', '');

        if ($root !== '' && ! str_ends_with(rtrim($override, '/'), rtrim(basename($root), '/'))) {
            $this->failures[] = "config('tenancy.filesystem.root_override.local') is '{$override}', which does not end in the same directory as config('filesystems.disks.local.root') ('{$root}') — tenant reads and writes then land beside the disk rather than inside it.";
        }
    }

    /**
     * Two-factor and passkeys have neither views nor columns here, so enabling
     * either registers routes that 500 on the first request.
     */
    private function verifyFortifyFeatures(): void
    {
        /** @var list<string> $features */
        $features = Config::array('fortify.features', []);

        $unsupported = array_values(array_intersect($features, [
            FortifyFeatures::twoFactorAuthentication(),
            FortifyFeatures::passkeys(),
        ]));

        if ($unsupported !== []) {
            $this->failures[] = "config('fortify.features') enables ".implode(', ', $unsupported).', which this package ships neither views nor columns for. Remove them, or supply both yourself and set numerosis.auth.manage_fortify_features to false.';
        }
    }

    private function verifyCentralDomains(): void
    {
        $key = 'tenancy.central_domains';
        $domains = Config::get($key);

        if (! is_array($domains) || $domains === []) {
            $this->failures[] = "config('{$key}') must list at least one hostname — Numerosis::routes() registers one route group per entry, so an empty list means every central URL 404s with no route registered at all.";
        }
    }

    /** Bounding lock waits is optional; bounding only half of it is a trap. */
    private function verifyLockWaitTimeout(): void
    {
        $connections = Config::array('database.connections');
        $central = $connections['central'] ?? null;

        if (! is_array($central) || ($central['driver'] ?? null) !== 'mysql') {
            return; // Only MySQL has these session variables.
        }

        $options = $central['options'] ?? [];
        $init = is_array($options) ? implode(' ', array_filter($options, is_string(...))) : '';

        $metadata = str_contains($init, 'lock_wait_timeout');
        $rows = str_contains($init, 'innodb_lock_wait_timeout');

        if ($metadata && ! $rows) {
            $this->failures[] = "config('database.connections.central.options') sets lock_wait_timeout but not innodb_lock_wait_timeout — the first bounds only metadata/DDL locks, so a blocked INSERT or DELETE still waits out MySQL's 50s default. Set both in one SET SESSION statement, or neither.";
        }
    }

    private function verifyDatabaseConnections(): void
    {
        $connections = Config::array('database.connections');

        // `central` is the only connection configured statically. The
        // `tenant` connection is built at runtime for each tenant.
        if (! array_key_exists('central', $connections)) {
            $this->failures[] = "config('database.connections.central') is missing — see docs/host-requirements.md's config/database.php row.";
        }

        // Driver as well as presence: on SQLite this surfaces as a tenant
        // seeder dying on `unknown function: SUBSTRING_INDEX()`, five queued
        // jobs after the real mistake.
        $driver = Config::get('database.connections.central.driver');

        if (is_string($driver) && ! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->failures[] = "config('database.connections.central.driver') is '{$driver}'; tenancy needs CREATE DATABASE per tenant and MySQL-only generated columns — see docs/host-requirements.md's DB credentials row.";
        }

        $template = Config::get('tenancy.database.template_tenant_connection');

        if (is_string($template) && ! array_key_exists($template, $connections)) {
            $this->failures[] = "config('tenancy.database.template_tenant_connection') names '{$template}', which is not in config('database.connections').";
        }
    }

    private function verifySessionDomain(): void
    {
        $domain = Config::get('session.domain');

        if (! is_string($domain) || ! str_starts_with($domain, '.')) {
            $this->failures[] = "config('session.domain') must start with a leading dot (e.g. '.example.com') — see docs/host-requirements.md's config/session.php row.";
        }
    }

    private function verifyAuthGuards(): void
    {
        $guards = Config::array('auth.guards');

        foreach (Context::cases() as $context) {
            $guardName = Config::get("numerosis.auth.guards.{$context->value}");

            if (! is_string($guardName) || ! array_key_exists($guardName, $guards)) {
                $this->failures[] = "config('numerosis.auth.guards.{$context->value}') does not resolve to a real guard in config('auth.guards') — see docs/host-requirements.md's config/auth.php row.";
            }
        }
    }

    private function verifyAuthPasswordBroker(): void
    {
        $broker = Config::get('auth.defaults.passwords');

        if (! is_string($broker) || $broker === '') {
            $this->failures[] = "config('auth.defaults.passwords') is unset — password resets resolve Laravel's generic user model and fail with 'Call to undefined method ...User::notify()'.";

            return;
        }

        $brokers = Config::get('auth.passwords');
        $config = is_array($brokers) ? ($brokers[$broker] ?? null) : null;

        if (! is_array($config)) {
            $this->failures[] = "config('auth.passwords.{$broker}') is missing, though config('auth.defaults.passwords') names it.";

            return;
        }

        $provider = $config['provider'] ?? null;
        $providers = Config::get('auth.providers');

        if (! is_string($provider) || ! is_array($providers) || ! array_key_exists($provider, $providers)) {
            $this->failures[] = "config('auth.passwords.{$broker}.provider') does not name a provider in config('auth.providers').";
        }
    }

    private function verifySocialRoutes(): void
    {
        foreach (['redirect', 'callback'] as $route) {
            $name = Config::get("numerosis.social.routes.{$route}.name");

            if (! is_string($name) || $name === '') {
                $this->failures[] = "config('numerosis.social.routes.{$route}.name') must name a route — the social-login views pass it straight to route(), so an unset key surfaces as `Route [] not defined` from a view rather than as missing config.";
            }
        }
    }

    private function verifyFailedJobsConnection(): void
    {
        $connection = Config::get('queue.failed.database');

        if (! is_string($connection) || $connection === '') {
            $this->failures[] = "config('queue.failed.database') must name a database connection — see docs/host-requirements.md's config/queue.php row.";

            return;
        }

        if (! Schema::connection($connection)->hasTable('failed_jobs')) {
            $this->failures[] = "config('queue.failed.database') is '{$connection}', which has no `failed_jobs` table. Run the package's central migrations against it, or point the key at the central connection.";
        }
    }

    /** The disk's name doesn't matter; that it is never tenant-suffixed does. */
    private function verifyLivewireUploadDisk(): void
    {
        $disk = Config::get('livewire.temporary_file_upload.disk') ?? 'local';

        if (! is_string($disk)) {
            $this->failures[] = "config('livewire.temporary_file_upload.disk') must be a disk name.";

            return;
        }

        $tenantDisks = Config::array('tenancy.filesystem.disks');

        if (in_array($disk, $tenantDisks, true)) {
            $this->failures[] = "config('livewire.temporary_file_upload.disk') is '{$disk}', which config('tenancy.filesystem.disks') tenant-suffixes — uploads then land outside the root the validating request reads, surfacing as a mimetype rejection. This package sets it to a dedicated 'livewire' disk by default (see NumerosisServiceProvider::packageBooted()); if you overrode it, point the override at a disk absent from that list instead.";
        }
    }

    private function verifyLivewireComponentNamespaces(): void
    {
        $namespaces = Config::get('livewire.component_namespaces');
        $namespaces = is_array($namespaces) ? $namespaces : [];

        foreach (['numerosis-layouts', 'numerosis-pages'] as $namespace) {
            $path = $namespaces[$namespace] ?? null;

            if (! is_string($path) || ! File::isDirectory($path)) {
                $this->failures[] = "config('livewire.component_namespaces.{$namespace}') must point at an existing directory. This package registers it against its own resources/views by default (see NumerosisServiceProvider::registerLivewireComponentNamespaces()); if you overrode it, point the override at a real directory. Unset entirely, the package's own components fail with 'Unable to find component'.";
            }
        }
    }

    private function verifyDomainConfig(): void
    {
        foreach (['apex', 'central'] as $key) {
            $value = Config::get("numerosis.domains.{$key}");

            if (! is_string($value) || $value === '') {
                $this->failures[] = "config('numerosis.domains.{$key}') is unset. The package ships a default derived from APP_URL, so this almost always means config/numerosis.php was published before this key existed — re-publish it with `php artisan vendor:publish --tag=numerosis-config --force`, re-applying your own edits.";
            }
        }

        // Only meaningful under IdentificationMode::Subdomain: CustomDomain
        // uses a fixed '{tenant}' pattern internally, Path uses none at all.
        if (IdentificationMode::current() !== IdentificationMode::Subdomain) {
            return;
        }

        $pattern = Config::get('numerosis.domains.tenant_pattern');

        if (! is_string($pattern) || ! str_contains($pattern, '{tenant}')) {
            $this->failures[] = "config('numerosis.domains.tenant_pattern') must contain the literal '{tenant}' placeholder — the tenant's subdomain is substituted into it, and a pattern without it routes every tenant to the same host.";
        }
    }

    /**
     * Validates any tenant-migration path of your own. Paths are not checked
     * for existence, since an empty `database/migrations/tenant` is normal
     * until you have tenant migrations of your own.
     */
    private function verifyTenantMigrationPath(): void
    {
        $parameters = Config::array('tenancy.migration_parameters');
        $paths = $parameters['--path'] ?? null;

        if (! is_array($paths) || $paths === []) {
            $this->failures[] = "config('tenancy.migration_parameters')['--path'] is missing — see docs/host-requirements.md's config/tenancy.php row.";

            return;
        }

        $vendorPath = Numerosis::tenantMigrationPath();

        foreach ($paths as $path) {
            if (! is_string($path)) {
                $this->failures[] = "config('tenancy.migration_parameters')['--path'] contains a non-string entry.";

                continue;
            }

            if ($path === $vendorPath) {
                continue;
            }

            if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
                $this->failures[] = "config('tenancy.migration_parameters')['--path'] must be absolute, got '{$path}' — pass --realpath.";
            }
        }
    }

    /**
     * Warns when one of a host's migrations shares a filename with one of the
     * package's central ones. The migrator keys migrations by filename without
     * extension and silently drops the duplicate, and `database/migrations` is
     * appended after every package path, so the host's copy always wins and
     * the package's never runs.
     */
    private function verifyCentralMigrationCollisions(): void
    {
        $hostDirectory = database_path('migrations');

        if (! File::isDirectory($hostDirectory)) {
            return;
        }

        $packageMigrations = [];

        // Not allFiles(): the migrator globs each registered path without
        // recursing, so `database/migrations/tenant` (published tenant
        // migrations, a deliberate copy) is a different path with no clash.
        foreach (File::files(dirname(__DIR__, 3).'/database/migrations/central') as $file) {
            $packageMigrations[$file->getFilenameWithoutExtension()] = true;
        }

        $collisions = [];

        foreach (File::files($hostDirectory) as $file) {
            if (isset($packageMigrations[$file->getFilenameWithoutExtension()])) {
                $collisions[] = $file->getPathname();
            }
        }

        if ($collisions === []) {
            return;
        }

        $this->components->warn('These migrations share a filename with one of this package\'s central migrations, so yours runs and the package\'s copy is silently skipped — delete yours, or merge what it adds into the package copy\'s tenant-aware schema:');

        foreach ($collisions as $collision) {
            $this->line("  - {$collision}");
        }
    }

    /**
     * Warns when the tenant resolver cache is off because the host's
     * `cache.serializable_classes` cannot round-trip the tenant model the
     * resolver caches. Domain and path mode hold that flag on separate
     * classes, so this reports on whichever one the configured mode uses.
     */
    private function verifyTenantResolverCache(): void
    {
        $path = IdentificationMode::current() === IdentificationMode::Path;

        if ($path ? PreservingPathTenantResolver::$shouldCache : DomainTenantResolver::$shouldCache) {
            return;
        }

        if (Config::get('numerosis.tenancy.cache_resolved_tenants') === false) {
            return;
        }

        $this->components->warn(
            'The '.($path ? 'path' : 'domain').'-to-tenant resolver cache is disabled because config(\'cache.serializable_classes\') is '
            .var_export(Config::get('cache.serializable_classes'), true)
            .', which cannot round-trip a cached tenant model. Every tenant request pays a central-database lookup before anything else runs. To turn it back on, add '
            .Config::string('tenancy.tenant_model', Tenant::class)
            .' to that allowlist (or set it to true), then re-run this command. Set numerosis.tenancy.cache_resolved_tenants to false to silence this deliberately.'
        );
    }

    /**
     * Warns when a published asset differs from the package's own copy.
     * Editing them is allowed and expected; this exists because the Stripe
     * scripts among them are load-bearing for payment, and drifting from
     * the original by accident is expensive.
     */
    private function verifyPublishedAssetsMatchSource(): void
    {
        $diverged = [];

        foreach (Numerosis::assetSourcePaths() as $source => $target) {
            if (! File::isDirectory($target)) {
                continue;
            }

            foreach (File::allFiles($source) as $file) {
                $relative = $file->getRelativePathname();
                $targetFile = $target.DIRECTORY_SEPARATOR.$relative;

                if (! File::exists($targetFile)) {
                    continue;
                }

                if (File::hash($file->getPathname()) === File::hash($targetFile)) {
                    continue;
                }

                // Every Laravel skeleton ships an `app.css`/`app.js`, so an
                // unequal hash at those two paths does not mean the file was
                // ever ours. Require a marker only a published copy carries.
                $fingerprints = [
                    'app.css' => 'vendor/nvade/numerosis/resources/css/tokens.css',
                    'app.js' => 'virtual:livewire-hot-reload',
                ];

                if (isset($fingerprints[$relative]) && ! str_contains((string) File::get($targetFile), $fingerprints[$relative])) {
                    continue;
                }

                $diverged[] = $targetFile;
            }
        }

        if ($diverged !== []) {
            $this->components->warn('Published assets differ from the package originals — this is allowed, but check the diff is intentional:');

            foreach ($diverged as $file) {
                $this->line("  - {$file}");
            }
        }
    }

    /**
     * Confirms both prebuilt bundles reached the public path, once one of them
     * has. Never publishing them is supported and reported as a manual step
     * below; a half-landed publish is the silent case, since `Assets::tags()`
     * links both URLs whether or not the files exist.
     */
    private function verifyPublicAssets(): void
    {
        $paths = Assets::publishedPaths();

        if (! File::isDirectory(dirname($paths['css']))) {
            return;
        }

        foreach ($paths as $path) {
            if (! File::exists($path)) {
                $this->failures[] = "public/vendor/numerosis exists but {$path} does not — run `php artisan vendor:publish --tag=numerosis-public-assets --force` again, or every page rendering resources/views/partials/styles.blade.php links a 404 for it.";
            }
        }
    }

    private function verifyStripeKeys(): void
    {
        foreach (['key', 'secret'] as $setting) {
            $value = Config::get("cashier.{$setting}");

            if (! is_string($value) || $value === '') {
                $this->failures[] = "config('cashier.{$setting}') is not set — add STRIPE_".strtoupper($setting).' to .env.';
            }
        }
    }

    /**
     * Catches a configured model override that doesn't exist or doesn't
     * subclass the package model, and a published model stub that
     * {@see Numerosis::model()} is silently not picking up.
     */
    private function verifyModelOverrides(): void
    {
        foreach ($this->modelStubMap() as $packageModel => $stub) {
            /** @var class-string<Model> $packageModel */
            $configured = Config::get("numerosis.models.{$packageModel}");

            if (is_string($configured) && $configured !== '') {
                if (! class_exists($configured)) {
                    $this->failures[] = "config('numerosis.models.{$packageModel}') names '{$configured}', which does not exist.";

                    continue;
                }

                if (! is_subclass_of($configured, $packageModel)) {
                    $this->failures[] = "config('numerosis.models.{$packageModel}') names '{$configured}', which does not extend {$packageModel} — an override must be a subclass, or package code hands Eloquent a class it knows nothing about.";
                }

                continue;
            }

            if (File::exists($stub['path']) && Numerosis::model($packageModel) === $packageModel) {
                $this->failures[] = "A model stub is published at {$stub['path']}, but Numerosis::model({$packageModel}::class) still resolves to the package's own class — the stub either doesn't extend {$packageModel}, or its class name doesn't match {$stub['class']}. Every package call site keeps using the package's own class, so rows created through the stub are written with the wrong class-string (this surfaces later as SQLSTATE 1205/1062 on an unrelated insert).";
            }
        }
    }

    /**
     * The one check about data, all the others being about configuration.
     * Skipped when the tables don't exist yet, which `php artisan migrate`
     * reports better.
     */
    private function verifyCentralDataSeeded(): void
    {
        $connection = Config::string('database.connections.central.database', '') !== ''
            ? 'central'
            : Config::string('database.default');

        foreach (['permissions', 'payment_plans'] as $table) {
            if (! Schema::connection($connection)->hasTable($table)) {
                return;
            }
        }

        $database = DB::connection($connection);

        if ($database->table('permissions')->count() === 0) {
            $this->failures[] = 'The central `permissions` table is empty — Spatie throws PermissionDoesNotExist rather than returning false, so every policy check 500s with "There is no permission named …", which reads as a guard bug. Run `php artisan numerosis:install` (seeds by default).';
        }

        if ($database->table('payment_plans')->count() === 0) {
            $this->failures[] = 'The central `payment_plans` table is empty — the registration wizard has nothing to sell and renders an empty plan step. Run `php artisan numerosis:install` (seeds by default).';
        }
    }

    /**
     * The models you may override, each with the path its stub publishes to
     * and the class name {@see Numerosis::model()} expects it under.
     *
     * @return array<class-string, array{path: string, class: string}>
     */
    private function modelStubMap(): array
    {
        $namespace = $this->laravel->getNamespace();
        $map = [];

        foreach (Numerosis::modelStubs() as $packageModel => $relative) {
            $map[$packageModel] = [
                'path' => app_path("Models/{$relative}.php"),
                'class' => $namespace.'Models\\'.str_replace('/', '\\', $relative),
            ];
        }

        return $map;
    }

    private function printManualSteps(): void
    {
        $this->newLine();
        $this->components->info('Manual steps this command cannot do for you:');
        $this->line('  1. '.match (IdentificationMode::current()) {
            IdentificationMode::Subdomain => 'Wildcard DNS: point *.'.Config::string('numerosis.domains.tenant_pattern', '{tenant}.your-domain').' at this app.',
            IdentificationMode::CustomDomain => 'DNS: each tenant points their own custom domain at this app (CNAME or A record) — no wildcard DNS needed.',
            IdentificationMode::Path => 'No DNS changes needed — tenants are identified by URL path under this app\'s own domain.',
        });
        $this->line('  2. Run a queue worker on the dedicated "provisioning" queue (`php artisan queue:work --queue=provisioning`) — tenant provisioning is queued there, not on the default worker.');
        $this->line('  3. Run `php artisan vendor:publish --tag=numerosis-public-assets` — it copies this package\'s prebuilt dist/numerosis.js and dist/numerosis.css to public/vendor/numerosis/. No vite.config.js entry needed: neither goes through your build unless you\'ve published and customised resources/js/numerosis.js yourself (Numerosis::assetTags() prefers your own Vite manifest entry for it when one exists).');
        $this->line('  4. To rebrand (accent colour, radius, fonts, density), add a `:root { --pref-...: ...; }` block to your published resources/css/app.css AFTER its `@import \'.../vendor/nvade/numerosis/resources/css/tokens.css\';` line, and rebuild. See tokens.css for the full list of overridable custom properties. Note the prebuilt dist/numerosis.css from step 3 is compiled once against the default `--pref-accent-hue` and does not read your app.css, so rebranding means building your own CSS through Vite rather than relying on that bundle. This is a one-time, host-level choice — Numerosis has no per-user or per-tenant theme picker. A custom `--pref-accent-hue` is not contrast-verified for you — white text on `--color-primary` is only checked against the default hue; check your own hue\'s contrast (browser devtools\' contrast checker is enough) before shipping it.');
    }
}
