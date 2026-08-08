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
use Nvade\Numerosis\Services\Tenancy\Bootstrappers\AuthGuardBootstrapper;
use Nvade\Numerosis\Services\Tenancy\Bootstrappers\SpatiePermissionsBootstrapper;
use Nvade\Numerosis\Support\Numerosis;

/**
 * Publishes config + model stubs, appends the env keys host-requirements.md
 * documents, then verifies the result rather than trusting it — a host that
 * skips a step here fails at first tenant request with an error that reads
 * like a routing or database bug (see docs/host-requirements.md's own
 * "why the package can't own this file" note), not at install time. Every
 * check below mirrors a row in that document; the two must not drift.
 */
class InstallNumerosisCommand extends Command
{
    public $signature = 'numerosis:install
                        {--verify-only : Run the host-configuration checks without publishing anything or touching .env}
                        {--seed : Also run the package\'s central seeders (roles/permissions, example plans, module catalogue)}';

    public $description = 'Publish Numerosis config and model stubs, then verify the host is wired correctly';

    /** @var list<string> */
    private array $failures = [];

    /**
     * Overrides written to .env during this run. Config was already resolved
     * when they landed, so `config('numerosis.models.*')` still reads null for
     * them — verification has to consult this instead of reporting a problem
     * the command just fixed.
     *
     * @var array<class-string, string>
     */
    private array $appendedModelOverrides = [];

    public function handle(): int
    {
        if (! $this->option('verify-only')) {
            $this->publishAssets();
            $this->appendEnvKeys();
            $this->appendModelOverrides();
        }

        if ($this->option('seed') && ! $this->option('verify-only')) {
            $this->seedCentralData();
        }

        $this->newLine();
        $this->components->info('Verifying host configuration');

        $this->verifyTenancyModels();
        $this->verifyCentralDomains();
        $this->verifyTenancyBootstrappers();
        $this->verifyDatabaseConnections();
        $this->verifyLockWaitTimeout();
        $this->verifySessionDomain();
        $this->verifyAuthGuards();
        $this->verifyAuthPasswordBroker();
        $this->verifySocialProviders();
        $this->verifySocialRoutes();
        $this->verifyFailedJobsConnection();
        $this->verifyLivewireDiskExclusion();
        $this->verifyLivewireUploadDisk();
        $this->verifyLivewireComponentNamespaces();
        $this->verifyDomainConfig();
        $this->verifyTenantMigrationPath();
        $this->verifyPublishedAssetsMatchSource();
        $this->verifyFilamentThemeAsset();
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

    private function publishAssets(): void
    {
        $this->call('vendor:publish', ['--tag' => 'numerosis-config', '--force' => false]);
        $this->call('vendor:publish', ['--tag' => 'numerosis-models', '--force' => false]);

        // numerosis-tenant-migrations is NOT published here: the default,
        // verified path is the vendor directory itself
        // (Numerosis::tenantMigrationPath()), and publishing is only the
        // opt-in escape hatch for a host that needs to customise a
        // migration. Auto-publishing it here would recreate the duplicated,
        // drifting copy this command exists to prevent.

        // resources/views/partials/styles.blade.php calls
        // @vite('resources/js/central.js') / 'resources/js/tenant.js'
        // unconditionally — without this, that view throws "Unable to
        // locate file in Vite manifest" the moment it's first rendered,
        // since those files never otherwise land in the host's
        // resources/js. Publishing them is still not the whole fix — see
        // printManualSteps() below for the vite.config.js entry this
        // can't add on its own.
        $this->call('vendor:publish', ['--tag' => 'numerosis-assets', '--force' => false]);
    }

    /**
     * Runs the package's own central seeders.
     *
     * A host's `database/seeders/DatabaseSeeder` is its own file — Laravel's
     * skeleton ships one, and `db:seed` runs *that*, so nothing the package
     * seeds is reachable unless the host edits it. thin-app never did, and
     * the result was a central database with zero permissions and zero
     * payment plans while every other check here passed. Zero permissions is
     * not a missing-data inconvenience: Spatie throws `PermissionDoesNotExist`
     * rather than returning false, so the first admin-panel request 500s with
     * "There is no permission named …", which reads as a guard bug
     * (.claude/rules/auth-guards.md). Zero plans means the registration
     * wizard has nothing to sell.
     *
     * Resolved from the container rather than run through `db:seed`, for the
     * reason `.claude/rules/tenant-provisioning.md` records at length:
     * `Stancl\Tenancy\Commands\Seed` registers itself under the name `db:seed`
     * (it inherits `Illuminate\Database\Console\Seeds\SeedCommand`'s
     * `$signature` and never overrides it), and the console app resolves that
     * collision in stancl's favour. So `$this->call('db:seed', …)` reaches
     * *stancl's* command, whose `handle()` immediately calls
     * `$this->option('tenants')` — an option its own shadowed constructor
     * never registered — and throws `InvalidArgumentException: The "tenants"
     * option does not exist`. Confirmed here by writing it the obvious way
     * first and watching it throw.
     *
     * `setContainer()` + `Model::unguarded()` replicate what
     * `SeedCommand::handle()` does around a seeder resolved this way.
     */
    private function seedCentralData(): void
    {
        $this->newLine();
        $this->components->info('Seeding central data');

        // Every seeder underneath keys on a natural key, so this is safe on
        // an already-seeded database — which is the point, since the whole
        // command is meant to be re-runnable.
        Model::unguarded(function (): void {
            $this->laravel->make(DatabaseSeeder::class)
                ->setContainer($this->laravel)
                ->setCommand($this)
                ->__invoke();
        });
    }

    /**
     * Appends the env keys docs/host-requirements.md documents as required,
     * if missing — never overwrites a key the host already set.
     */
    private function appendEnvKeys(): void
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath)) {
            $this->components->warn('.env not found — skipping env key check.');

            return;
        }

        $env = File::get($envPath);

        // No DOMAIN / CENTRAL_SUBDOMAIN here any more: the domain values now
        // derive from APP_URL (config/numerosis.php's `domains` block, via
        // Support\Domains), so a host that sets nothing is already correct.
        // NUMEROSIS_APEX_DOMAIN / NUMEROSIS_CENTRAL_DOMAIN exist only to
        // override that derivation, and appending a *commented* override is
        // noise while appending an uncommented one would replace a working
        // default with a guess.
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

    /**
     * Points config at the model stubs that were just published. Publishing a
     * stub does nothing on its own: every package call site resolves through
     * `Numerosis::model()`, which returns the package's own class unless
     * `numerosis.models.<FQCN>` names something else. A host that publishes
     * stubs, creates rows through them, and leaves config unset gets package
     * class-strings written into morph columns and Cashier's `updateOrCreate`
     * missing rows it should have found — surfacing as SQLSTATE 1205/1062 on
     * an unrelated insert, which reads as lock contention rather than as a
     * config gap.
     *
     * Values are single-quoted. A double-quoted class-string
     * (`"App\Models\Central\Tenant"`) is an unrecognised escape sequence to
     * phpdotenv, which throws `InvalidFileException` for the *whole file* —
     * i.e. writing one here would stop the host booting at all.
     */
    private function appendModelOverrides(): void
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath)) {
            return; // appendEnvKeys() already warned about this.
        }

        $env = File::get($envPath);
        $missing = [];

        foreach ($this->modelStubMap() as $packageModel => $stub) {
            // No stub published means the host is running on the package's own
            // models, which is a supported shape (D8) — nothing to point at.
            if (! File::exists($stub['path'])) {
                continue;
            }

            if (preg_match('/^'.preg_quote($stub['env'], '/').'=/m', $env) === 1) {
                continue;
            }

            $missing[] = "{$stub['env']}='{$stub['class']}'";
            $this->appendedModelOverrides[$packageModel] = $stub['class'];
        }

        if ($missing === []) {
            return;
        }

        File::append($envPath, "\n# Added by numerosis:install — published model stubs, see docs/host-requirements.md\n".implode("\n", $missing)."\n");

        $this->components->info('Pointed '.count($missing).' model override(s) at the published stubs.');
    }

    /**
     * The four model keys stancl and this package's own resolvers read. An
     * unresolvable `tenant_model` does not throw where it is read — the
     * Filament panel answers 404 on every tenant URL instead, because
     * `{tenant}` route-model binding silently resolves to nothing (see
     * filament-tenancy.md).
     */
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

        // Mirror SeedCommand::getSeeder(): an unqualified name resolves under
        // Database\Seeders, so 'DatabaseSeeder' is valid config, not a typo.
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

    /**
     * Both package bootstrappers must be present. `AuthGuardBootstrapper` is
     * the entire enforcement mechanism for "central domain = central guard,
     * inside tenant = tenant guard" — omit it and every ambient
     * `auth()->user()` resolves a central user on tenant domains, silently.
     */
    private function verifyTenancyBootstrappers(): void
    {
        $bootstrappers = Config::get('tenancy.bootstrappers');
        $bootstrappers = is_array($bootstrappers) ? $bootstrappers : [];

        foreach ([SpatiePermissionsBootstrapper::class, AuthGuardBootstrapper::class] as $required) {
            if (! in_array($required, $bootstrappers, true)) {
                $this->failures[] = "config('tenancy.bootstrappers') is missing {$required} — see docs/host-requirements.md's config/tenancy.php row for what stops working without it.";
            }
        }
    }

    /**
     * Bounding these is optional — production hosts commonly leave them at
     * MySQL's defaults — but setting only `lock_wait_timeout` is a trap worth
     * failing on: it bounds metadata/DDL locks only, while the ordinary
     * row/FK waits an `INSERT`/`DELETE` blocks on keep waiting out
     * `innodb_lock_wait_timeout`'s 50s default. A host that sets one believes
     * it has bounded lock waits and has not.
     *
     * Read as a string rather than through PDO's constant, so this behaves the
     * same on PHP 8.4 and 8.5, where the MySQL init-command constant moved to
     * `Pdo\Mysql`.
     */
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

        // Only 'central' is a static config key. 'tenant' is stancl's
        // reserved name for a connection it builds dynamically at
        // tenancy-bootstrap time from 'template_tenant_connection' — it
        // never exists in config('database.connections') outside a request
        // that has actually initialized tenancy, so checking for it here
        // always fails on a freshly installed host. See config/tenancy.php's
        // own "don't name your template connection tenant" comment.
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
            $guardName = Config::get("auth.defaults.guards.context.{$context}");

            if (! is_string($guardName) || ! array_key_exists($guardName, $guards)) {
                $this->failures[] = "config('auth.defaults.guards.context.{$context}') does not resolve to a real guard in config('auth.guards') — see docs/host-requirements.md's config/auth.php row.";
            }
        }
    }

    /**
     * `Password::sendResetLink()` resolves its user model through the broker
     * config, not through `auth.providers`. With no broker entry Laravel falls
     * back to its own generic `Illuminate\Foundation\Auth\User`, which has no
     * `Notifiable` trait — so the failure is `Call to undefined method
     * ...User::notify()`, reading as a broken model rather than missing config.
     */
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

    /**
     * `ConfiguredProviders::all()` reads this with `Config::array()`, which
     * throws on a *missing* key rather than returning `[]` — an absent key
     * surfaces as a 500 from an unrelated view. An empty array is correct when
     * SocialLoginFeature is off.
     */
    private function verifySocialProviders(): void
    {
        if (! is_array(Config::get('auth.social.providers'))) {
            $this->failures[] = "config('auth.social.providers') must be an array (use [] when SocialLoginFeature is off) — a missing key throws InvalidArgumentException from whichever view renders the social-login buttons.";
        }
    }

    /**
     * Both names are read *inside* a `route()` call, so an unset key becomes
     * `route(null)` and the host sees `Route [] not defined` raised from a
     * Blade view — naming neither the config key nor the route it wanted.
     */
    private function verifySocialRoutes(): void
    {
        if (! is_array(Config::get('auth.social.providers')) || Config::get('auth.social.providers') === []) {
            return;
        }

        foreach (['redirect', 'login'] as $route) {
            $name = Config::get("auth.social.routes.{$route}.name");

            if (! is_string($name) || $name === '') {
                $this->failures[] = "config('auth.social.routes.{$route}.name') must name a route — the social-login views pass it straight to route(), so an unset key surfaces as `Route [] not defined` from a view rather than as missing config.";
            }
        }
    }

    /**
     * The insert is done by `queue:work`'s own JobFailed listener, so a
     * connection with no `failed_jobs` table does not lose a log line — it
     * throws inside the worker, from framework code, naming neither this key
     * nor the table.
     */
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

    private function verifyLivewireDiskExclusion(): void
    {
        /** @var list<string> $disks */
        $disks = Config::array('tenancy.filesystem.disks');

        if (in_array('livewire', $disks, true)) {
            $this->failures[] = "config('tenancy.filesystem.disks') must NOT contain 'livewire' — see docs/host-requirements.md's config/filesystems.php row.";
        }
    }

    /**
     * The real invariant is not the disk's *name* but that Livewire's
     * temporary-upload disk is never tenant-suffixed: that route runs
     * central-only (no `tenancy.identification`), so a tenant-suffixed root
     * means the upload writes to one directory and the tenant-panel page
     * validating it reads another. It surfaces as a mimetype rejection —
     * "must be a file of type: image/*" — not as a missing file.
     *
     * `NumerosisServiceProvider::packageBooted()` already sets this to
     * 'livewire' when the host hasn't set anything itself, so a passing
     * host normally never touched this config key at all. This is
     * therefore asking "has the host broken what we set", not "did the
     * host wire this up" — the failure that matters is a host explicitly
     * repointing it at a disk that's tenant-suffixed.
     */
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

    /**
     * Livewire's own default points these at `resource_path()`, which is
     * correct for a single-repo app and wrong here: the views ship from this
     * package. `NumerosisServiceProvider::packageBooted()` already points
     * both at the package's own resources/views/{layouts,pages} whenever the
     * host hasn't set something else — so a passing host normally never
     * touched this key. This asks "has the host broken what we set", not
     * "did the host wire this up".
     */
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

    /**
     * Replaces the former `verifyAppDomain()`/`verifyCentralDefaultDomain()`,
     * which checked `app.domain` and `app.central.default` — keys this
     * package invented inside Laravel's own config/app.php and therefore
     * could not supply a default for. They live under `numerosis.domains.*`
     * now and default off `APP_URL`, so "unset" is no longer reachable
     * through the package's own config file.
     *
     * It is still reachable one way, which is why this check survives at all:
     * a host holding a **published** copy of config/numerosis.php from before
     * these keys existed. Laravel's `mergeConfigFrom()` merges only one level
     * deep, so the host's older `domains` array wins wholesale and the new
     * keys are simply absent — the same silent-loss shape D13 documents for
     * the deleted numerosis-billing/tenancy config files. The failures that
     * produces are worth naming: `Config::string()` throws on a missing key
     * rather than defaulting, taking out tenant creation and both panel
     * domain screens; and `Route::domain(null)` is a *getter* branch
     * returning the route's current domain string instead of `$this`, so
     * routes/auth.php fails one line later as
     * "Call to a member function name() on string".
     */
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
     * The package's own migrations must be read straight from the vendor
     * directory (`Numerosis::tenantMigrationPath()`), not from a copy the
     * host published — publishing is the opt-in customisation escape hatch,
     * not the default path. This checks the host's `--path` list includes
     * the vendor path itself, rather than merely checking some directory
     * exists, which is the question that let a duplicated, drifting copy
     * pass silently before.
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
        $foundVendorPath = false;

        foreach ($paths as $path) {
            if (! is_string($path)) {
                $this->failures[] = "config('tenancy.migration_parameters')['--path'] contains a non-string entry.";

                continue;
            }

            if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
                $this->failures[] = "config('tenancy.migration_parameters')['--path'] must be absolute, got '{$path}' — pass --realpath and point it at Numerosis::tenantMigrationPath().";

                continue;
            }

            if ($path === $vendorPath) {
                $foundVendorPath = true;

                continue;
            }

            if (! File::isDirectory($path)) {
                $this->failures[] = "config('tenancy.migration_parameters')['--path'] entry '{$path}' does not exist.";
            }
        }

        if (! $foundVendorPath) {
            $this->failures[] = "config('tenancy.migration_parameters')['--path'] does not include Numerosis::tenantMigrationPath() ('{$vendorPath}') — point it there instead of a published copy under database/migrations/tenant.";
        }
    }

    /**
     * `numerosis-assets` is a deliberate publish, not a mistake (see
     * NumerosisServiceProvider's comment on why resources/{css,js} have to
     * land at resource_path() directly), so a host is allowed to customise
     * the published copy. But three of the shipped JS files
     * (stripe-checkout.js, stripe-confirm.js, and whatever central.js
     * imports them by relative path) are load-bearing for payment, so
     * silent drift between the vendor original and a host's copy is worth
     * surfacing — this warns, it does not fail the install.
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
     * `NumerosisAdminPlugin`/`NumerosisTenantPlugin` both call
     * `->viteTheme('resources/css/filament-theme.css')` (design-system-
     * unification Phase 4) — Filament resolves that path through the same
     * `Vite` helper `@vite()` uses, against the host's own
     * `public/build/manifest.json`. A host that has not added the entry to
     * its `vite.config.js` gets no failure at all: `getTheme()` still
     * returns *something* (Filament's manifest lookup only throws for a
     * missing manifest, not a missing entry — falls through with no theme
     * stylesheet), so both panels render with zero CSS. That reads as a
     * broken deploy, not a missing config line, so this fails loudly
     * instead of leaving it to be discovered as an unstyled admin panel.
     *
     * No manifest at all is not a failure here — a host that has not run
     * its first `npm run build` yet fails identically at every other
     * `@vite()` call in the app, which is not this check's job to catch.
     */
    private function verifyFilamentThemeAsset(): void
    {
        $manifestPath = public_path('build/manifest.json');

        if (! File::exists($manifestPath)) {
            return;
        }

        $manifest = json_decode(File::get($manifestPath), true);

        if (! is_array($manifest) || ! array_key_exists('resources/css/filament-theme.css', $manifest)) {
            $this->failures[] = "public/build/manifest.json exists but has no 'resources/css/filament-theme.css' entry — add it to your vite.config.js input array and rebuild (npm run build), or both Filament panels render with none of Numerosis's theming.";
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
     * Three failure shapes, all of which a host hits silently otherwise: a
     * stub published but not configured (see appendModelOverrides()'s note on
     * the SQLSTATE it surfaces as), a configured class that does not exist,
     * and a configured class that is not a subclass — `Numerosis::model()`
     * returns whatever the key names, so an unrelated class is handed to
     * Eloquent and fails far from here.
     */
    private function verifyModelOverrides(): void
    {
        foreach ($this->modelStubMap() as $packageModel => $stub) {
            $configured = Config::get("numerosis.models.{$packageModel}");

            if (! is_string($configured) || $configured === '') {
                $configured = $this->appendedModelOverrides[$packageModel] ?? null;
            }

            if ($configured === null) {
                if (File::exists($stub['path'])) {
                    $this->failures[] = "A model stub is published at {$stub['path']}, but config('numerosis.models.{$packageModel}') is unset — every package call site keeps using the package's own class, so rows created through the stub are written with the wrong class-string (this surfaces later as SQLSTATE 1205/1062 on an unrelated insert). Set {$stub['env']}='{$stub['class']}' in .env.";
                }

                continue;
            }

            if (! class_exists($configured)) {
                $this->failures[] = "config('numerosis.models.{$packageModel}') names '{$configured}', which does not exist — check {$stub['env']} in .env.";

                continue;
            }

            if (! is_subclass_of($configured, $packageModel)) {
                $this->failures[] = "config('numerosis.models.{$packageModel}') names '{$configured}', which does not extend {$packageModel} — an override must be a subclass, or package code hands Eloquent a class it knows nothing about.";
            }
        }
    }

    /**
     * The one check here about *data* rather than configuration, and it earns
     * its place because both failures it catches surface as something else
     * entirely.
     *
     * An unseeded `permissions` table is a 500, not a 403: Spatie's
     * `hasPermissionTo()` throws `PermissionDoesNotExist` instead of returning
     * false, so the first policy consulted reports "There is no permission
     * named 'viewAny permissions' for guard 'web'" and the whole thing reads
     * as a guard misconfiguration (.claude/rules/auth-guards.md says to check
     * `SELECT COUNT(*) FROM permissions` before investigating guards — this is
     * that check, run before anyone has to). An unseeded `payment_plans` is
     * quieter and worse: the registration wizard renders an empty plan step,
     * which looks like a styling bug.
     *
     * Skipped rather than failed when the tables are absent — that is a
     * migration problem, and `php artisan migrate` reports it far better than
     * this command could.
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
            $this->failures[] = 'The central `permissions` table is empty — Spatie throws PermissionDoesNotExist rather than returning false, so every policy check 500s with "There is no permission named …", which reads as a guard bug. Run `php artisan numerosis:install --seed`.';
        }

        if ($schema->getConnection()->table('payment_plans')->count() === 0) {
            $this->failures[] = 'The central `payment_plans` table is empty — the registration wizard has nothing to sell and renders an empty plan step. Run `php artisan numerosis:install --seed`.';
        }
    }

    /**
     * The 9 models a host may override, each with the env key
     * config/numerosis.php reads it from and the path
     * NumerosisServiceProvider publishes its stub to. Kept in one place so
     * appendModelOverrides() and verifyModelOverrides() cannot disagree about
     * which models exist.
     *
     * @return array<class-string, array{env: string, path: string, class: string}>
     */
    private function modelStubMap(): array
    {
        /** @var array<class-string, array{string, string}> $models */
        $models = [
            Tenant::class => ['NUMEROSIS_MODEL_TENANT', 'Central/Tenant'],
            Domain::class => ['NUMEROSIS_MODEL_DOMAIN', 'Central/Domain'],
            CentralUser::class => ['NUMEROSIS_MODEL_CENTRAL_USER', 'Central/CentralUser'],
            Subscription::class => ['NUMEROSIS_MODEL_SUBSCRIPTION', 'Central/Subscription'],
            PaymentPlan::class => ['NUMEROSIS_MODEL_PAYMENT_PLAN', 'Central/PaymentPlan'],
            PendingTenantProvision::class => ['NUMEROSIS_MODEL_PENDING_TENANT_PROVISION', 'Central/PendingTenantProvision'],
            Invitation::class => ['NUMEROSIS_MODEL_INVITATION', 'Tenant/Invitation'],
            Module::class => ['NUMEROSIS_MODEL_MODULE', 'Tenant/Module'],
            TenantUser::class => ['NUMEROSIS_MODEL_TENANT_USER', 'Tenant/User'],
        ];

        $namespace = $this->laravel->getNamespace();
        $map = [];

        foreach ($models as $packageModel => [$env, $relative]) {
            $map[$packageModel] = [
                'env' => $env,
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
        $this->line('  2. Register the package\'s Filament panel plugin(s) in your own AdminPanelProvider / TenantAdminPanelProvider.');
        $this->line('  3. Run a queue worker on the dedicated "provisioning" queue (see docker/8.5/supervisord.conf\'s [program:queue-provisioning] in saas-m for the reference config) — tenant provisioning is queued there, not on the default worker.');
        $this->line('  4. Add resources/js/central.js and resources/js/tenant.js to your vite.config.js input array (just published to resources/js/ by this command) — Vite compiles per-app, so this list can\'t be published, only the files it points at. Skipping this fails at first render with "Unable to locate file in Vite manifest".');
        $this->line('  5. Add resources/css/filament-theme.css to that same vite.config.js input array and run npm run build — both Filament panels\' theming (colours, radius, Instrument Sans) comes from this file via ->viteTheme(). Skipping this renders both panels with no theme CSS at all, not an error.');
        $this->line('  6. To rebrand (accent colour, radius, fonts, density), add a `:root { --pref-...: ...; }` block to your published resources/css/app.css AFTER its `@import \'.../vendor/nvade/numerosis/resources/css/tokens.css\';` line — CSS cascade applies it everywhere: the main app and, via filament-theme.css, both panels. See tokens.css for the full list of overridable custom properties. This is a one-time, host-level choice — Numerosis has no per-user or per-tenant theme picker.');
    }
}
