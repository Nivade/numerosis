<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Nvade\Numerosis\Database\Seeders\DatabaseSeeder;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Invitation;
use Nvade\Numerosis\Models\Tenant\Module;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\NumerosisServiceProvider;
use Nvade\Numerosis\Support\HostConfig;
use Nvade\Numerosis\Support\Numerosis;

/**
 * Publishes config and model stubs, appends missing `.env` keys, seeds
 * central data, then verifies the result.
 *
 * The verification is the point: nearly every misconfiguration this catches
 * would otherwise surface much later as a 404, a 500, or a mimetype
 * rejection that names neither the config key nor the real cause. Re-run it
 * any time with `--verify-only`.
 */
class InstallNumerosisCommand extends Command
{
    public $signature = 'numerosis:install
                        {--verify-only : Run the host-configuration checks without publishing anything or touching .env}
                        {--no-seed : Skip the package\'s central seeders (roles/permissions, example plans, module catalogue) — run by default}';

    public $description = 'Publish Numerosis config and model stubs, then verify the host is wired correctly';

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
        $this->verifyCentralDomains();
        $this->verifyDatabaseConnections();
        $this->verifyLockWaitTimeout();
        $this->verifySessionDomain();
        $this->verifyAuthGuards();
        $this->verifyAuthPasswordBroker();
        $this->verifySocialProviders();
        $this->verifySocialRoutes();
        $this->verifyFailedJobsConnection();
        $this->verifyLivewireUploadDisk();
        $this->verifyLivewireComponentNamespaces();
        $this->verifyDomainConfig();
        $this->verifyTenantMigrationPath();
        $this->verifyPublishedAssetsMatchSource();
        $this->verifyFilamentThemeAsset();
        $this->verifyStripeKeys();
        $this->verifyModelOverrides();
        $this->verifyCentralDataSeeded();
        $this->verifyConfigSchemaVersion();

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

        // Starting templates for resources/css/app.css and resources/js.
        // Safe to delete any copy you don't intend to customize.
        $this->call('vendor:publish', ['--tag' => 'numerosis-assets', '--force' => false]);

        // Tenant migrations are deliberately not published: they run from
        // the package. Publish `numerosis-tenant-migrations` only to edit one.
    }

    /**
     * Seeds roles and permissions, example payment plans, and the module
     * catalogue. Safe to re-run: every seeder keys on natural keys.
     *
     * Skip with `--no-seed`, but note that an empty `permissions` table is
     * not merely missing data — Spatie throws rather than denying, so the
     * first admin-panel request 500s.
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
        foreach (['tenant_model', 'domain_model', 'central_user_model', 'tenant_user_model'] as $key) {
            $class = Config::get("tenancy.{$key}");

            if (! is_string($class) || $class === '' || ! class_exists($class)) {
                $this->failures[] = "config('tenancy.{$key}') must name a class that exists — a tenant panel answers 404 on every tenant URL when this is unresolvable, rather than reporting a config problem.";
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

    private function verifyCentralDomains(): void
    {
        $domains = Config::get('tenancy.central_domains');

        if (! is_array($domains) || $domains === []) {
            $this->failures[] = "config('tenancy.central_domains') must list at least one hostname — Numerosis::routes() registers one route group per entry, so an empty list means every central URL 404s with no route registered at all.";
        }
    }

    /** Bounding lock waits is optional; bounding only half of it is a trap. */
    private function verifyLockWaitTimeout(): void
    {
        /** @var array<string, mixed> $connections */
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
        /** @var array<string, mixed> $connections */
        $connections = Config::array('database.connections');

        // `central` is the only connection configured statically. The
        // `tenant` connection is built at runtime for each tenant.
        if (! array_key_exists('central', $connections)) {
            $this->failures[] = "config('database.connections.central') is missing — see docs/host-requirements.md's config/database.php row.";
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
        /** @var array<string, mixed> $guards */
        $guards = Config::array('auth.guards');

        foreach (['central', 'tenant'] as $context) {
            $guardName = Config::get("numerosis.auth.guards.{$context}");

            if (! is_string($guardName) || ! array_key_exists($guardName, $guards)) {
                $this->failures[] = "config('numerosis.auth.guards.{$context}') does not resolve to a real guard in config('auth.guards') — see docs/host-requirements.md's config/auth.php row.";
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

    /** An empty array is correct when social login is off; a missing key is not. */
    private function verifySocialProviders(): void
    {
        if (! is_array(Config::get('numerosis.social.providers'))) {
            $this->failures[] = "config('numerosis.social.providers') must be an array (use [] when SocialLoginFeature is off) — a missing key throws InvalidArgumentException from whichever view renders the social-login buttons.";
        }
    }

    private function verifySocialRoutes(): void
    {
        if (! is_array(Config::get('numerosis.social.providers')) || Config::get('numerosis.social.providers') === []) {
            return;
        }

        foreach (['redirect', 'login'] as $route) {
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

        /** @var list<string> $tenantDisks */
        $tenantDisks = Config::array('tenancy.filesystem.disks');

        if (in_array($disk, $tenantDisks, true)) {
            $this->failures[] = "config('livewire.temporary_file_upload.disk') is '{$disk}', which config('tenancy.filesystem.disks') tenant-suffixes — uploads then land outside the root the validating request reads, surfacing as a mimetype rejection. This package sets it to a dedicated 'livewire' disk by default (see NumerosisServiceProvider::packageBooted()); if you overrode it, point the override at a disk absent from that list instead.";
        }
    }

    private function verifyLivewireComponentNamespaces(): void
    {
        $namespaces = Config::get('livewire.component_namespaces');
        $namespaces = is_array($namespaces) ? $namespaces : [];

        foreach (['layouts', 'pages'] as $namespace) {
            $path = $namespaces[$namespace] ?? null;

            if (! is_string($path) || ! File::isDirectory($path)) {
                $this->failures[] = "config('livewire.component_namespaces.{$namespace}') must point at an existing directory. This package sets it to its own resources/views/{$namespace} by default (see NumerosisServiceProvider::packageBooted()); if you overrode it, point the override at a real directory. Unset entirely, components resolve against the host's resources/ and fail with 'Unable to find component'.";
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

        $pattern = Config::get('numerosis.domains.tenant_pattern');

        if (! is_string($pattern) || ! str_contains($pattern, '{tenant}')) {
            $this->failures[] = "config('numerosis.domains.tenant_pattern') must contain the literal '{tenant}' placeholder — Filament's ->tenantDomain() substitutes it, and a pattern without it routes every tenant to the same host.";
        }
    }

    /**
     * Validates any tenant-migration path of your own. Paths are not checked
     * for existence — an empty `database/migrations/tenant` is normal when
     * you have no tenant migrations of your own yet.
     */
    private function verifyTenantMigrationPath(): void
    {
        /** @var array<string, mixed> $parameters */
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

                if (File::hash($file->getPathname()) !== File::hash($targetFile)) {
                    $diverged[] = $targetFile;
                }
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
     * Confirms `filament:assets` published this package's assets alongside
     * Filament's own. Skipped entirely until you have run that command.
     */
    private function verifyFilamentThemeAsset(): void
    {
        $filamentAssetsDir = public_path('css/filament');

        if (! File::isDirectory($filamentAssetsDir)) {
            return;
        }

        $themePath = public_path('css/nvade/numerosis/'.NumerosisServiceProvider::THEME_ID.'.css');

        if (! File::exists($themePath)) {
            $this->failures[] = 'public/css/filament exists but public/css/nvade/numerosis/'.NumerosisServiceProvider::THEME_ID.'.css does not — run `php artisan filament:assets` again, or both Filament panels render with none of Numerosis\'s theming.';
        }

        $assetCssPath = public_path('css/nvade/numerosis/'.NumerosisServiceProvider::ASSET_ID.'.css');
        $assetJsPath = public_path('js/nvade/numerosis/'.NumerosisServiceProvider::ASSET_ID.'.js');

        if (! File::exists($assetCssPath) || ! File::exists($assetJsPath)) {
            $this->failures[] = 'public/css/filament exists but public/css/nvade/numerosis/'.NumerosisServiceProvider::ASSET_ID.'.css and/or public/js/nvade/numerosis/'.NumerosisServiceProvider::ASSET_ID.'.js does not — run `php artisan filament:assets` again, or every page rendering resources/views/partials/styles.blade.php fails resolving Numerosis::assetTags().';
        }
    }

    private function verifyStripeKeys(): void
    {
        foreach (['key', 'secret'] as $setting) {
            if (! is_string(Config::get("cashier.{$setting}")) || Config::get("cashier.{$setting}") === '') {
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
     * The one check about data rather than configuration. Skipped when the
     * tables don't exist yet — `php artisan migrate` reports that better.
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

        $schema = Schema::connection($connection);

        if ($schema->getConnection()->table('permissions')->count() === 0) {
            $this->failures[] = 'The central `permissions` table is empty — Spatie throws PermissionDoesNotExist rather than returning false, so every policy check 500s with "There is no permission named …", which reads as a guard bug. Run `php artisan numerosis:install` (seeds by default).';
        }

        if ($schema->getConnection()->table('payment_plans')->count() === 0) {
            $this->failures[] = 'The central `payment_plans` table is empty — the registration wizard has nothing to sell and renders an empty plan step. Run `php artisan numerosis:install` (seeds by default).';
        }
    }

    /**
     * Reports a published `config/numerosis.php` that predates a change to
     * the config's shape. Missing keys are backfilled for you; a key you
     * still name in an older shape cannot be, which is what this catches.
     *
     * Nothing to check if you have not published the config.
     */
    private function verifyConfigSchemaVersion(): void
    {
        $published = config_path('numerosis.php');

        if (! File::exists($published)) {
            return;
        }

        /** @var array<string, mixed> $hostConfig */
        $hostConfig = require $published;
        $hostVersion = is_int($hostConfig['schema_version'] ?? null) ? $hostConfig['schema_version'] : 0;

        /** @var array<string, mixed> $packageConfig */
        $packageConfig = require dirname(__DIR__, 2).'/config/numerosis.php';
        /** @var int $currentVersion */
        $currentVersion = $packageConfig['schema_version'];

        if ($hostVersion < $currentVersion) {
            $this->failures[] = "Published config/numerosis.php names schema_version {$hostVersion} (or none at all), but the package is on version {$currentVersion} — a top-level key may have been renamed or restructured since this file was written, which HostConfig's deep-fill cannot detect (it only backfills keys that are entirely missing, not ones your file still names with an old shape). Compare this file against the package's own config/numerosis.php, re-apply anything that changed, then set schema_version to {$currentVersion}.";
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
        /** @var array<class-string, string> $models */
        $models = [
            Tenant::class => 'Central/Tenant',
            Domain::class => 'Central/Domain',
            CentralUser::class => 'Central/CentralUser',
            Subscription::class => 'Central/Subscription',
            PaymentPlan::class => 'Central/PaymentPlan',
            PendingTenantProvision::class => 'Central/PendingTenantProvision',
            Invitation::class => 'Tenant/Invitation',
            Module::class => 'Tenant/Module',
            TenantUser::class => 'Tenant/User',
        ];

        $namespace = $this->laravel->getNamespace();
        $map = [];

        foreach ($models as $packageModel => $relative) {
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
        $this->line('  1. Wildcard DNS: point *.'.Config::string('numerosis.domains.tenant_pattern', '{tenant}.your-domain').' at this app.');
        $this->line('  2. Run a queue worker on the dedicated "provisioning" queue (`php artisan queue:work --queue=provisioning`) — tenant provisioning is queued there, not on the default worker.');
        $this->line('  3. Run `php artisan filament:assets` (you likely already run this for Filament itself) — it copies both Filament panels\' theming (colours, radius, Instrument Sans) plus this package\'s prebuilt dist/numerosis.js and dist/numerosis.css to public/{css,js}/nvade/numerosis/. No vite.config.js entry needed for any of it: none of it goes through your build unless you\'ve published and customised resources/js/numerosis.js yourself (Numerosis::assetTags() prefers your own Vite manifest entry for it when one exists).');
        $this->line('  4. To rebrand (accent colour, radius, fonts, density) for the *main app*, add a `:root { --pref-...: ...; }` block to your published resources/css/app.css AFTER its `@import \'.../vendor/nvade/numerosis/resources/css/tokens.css\';` line. See tokens.css for the full list of overridable custom properties. This does not reach either Filament panel — the panel theme from step 3 is a prebuilt file compiled once against the default `--pref-accent-hue` and does not read your app.css (a panel page loads only its own theme stylesheet, nothing else). Rebranding a panel\'s accent means building your own theme CSS (copy resources/theme-src/filament-theme.css from the package as a starting point, edit `--pref-accent-hue`, register it as your own Filament `Theme` asset or `->viteTheme()`) and pointing `->theme()`/`->viteTheme()` at it in your own panel providers instead of Numerosis\'s. This is a one-time, host-level choice either way — Numerosis has no per-user or per-tenant theme picker. A custom `--pref-accent-hue` is not contrast-verified for you — white text on `--color-primary` is only checked against the default hue; check your own hue\'s contrast (browser devtools\' contrast checker is enough) before shipping it.');
    }
}
