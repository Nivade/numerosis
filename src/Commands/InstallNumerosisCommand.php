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

    /**
     * `HostConfig::apply()` already ran, inside
     * `NumerosisServiceProvider::packageRegistered()`, before this command's
     * `handle()` was ever called — providers register ahead of any command
     * executing. This just surfaces what it did, so a host can see "these N
     * keys needed nothing from me" instead of having to diff every default
     * by hand. Runs regardless of `--verify-only`: normalization isn't a
     * side effect this command chooses to skip, it already happened.
     */
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

        // numerosis-tenant-migrations is NOT published here: the default,
        // verified path is the vendor directory itself
        // (Numerosis::tenantMigrationPath()), and publishing is only the
        // opt-in escape hatch for a host that needs to customise a
        // migration. Auto-publishing it here would recreate the duplicated,
        // drifting copy this command exists to prevent.

        // resources/css/app.css and resources/js/app.js are the host's own
        // starting templates — genuinely required, since nothing else
        // creates them. resources/js/numerosis.js in the same group is not:
        // Numerosis::assetTags() renders the prebuilt dist/numerosis.js
        // when this hasn't been published, same "opt-in customisation
        // escape hatch" shape as numerosis-tenant-migrations above. Published
        // here anyway since it rides the same tag as app.css/app.js —
        // --force=false means a host not customising it can simply leave
        // (or delete) the copy this creates.
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
     * The four model keys stancl and this package's own resolvers read. An
     * unresolvable `tenant_model` does not throw where it is read — the
     * Filament panel answers 404 on every tenant URL instead, because
     * `{tenant}` route-model binding silently resolves to nothing (see
     * filament-tenancy.md).
     *
     * `HostConfig::tenancyModels()`/`tenantSeederParameters()` already set
     * all of this from `Numerosis::model()` on every boot, so a host that
     * hasn't touched `config/tenancy.php` never fails here. This only
     * fires for a host that set one of these keys directly (bypassing
     * `Numerosis::model()`) to something broken.
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

    /**
     * `HostConfig::centralDomains()` derives this from `APP_URL` on every
     * boot whenever it's still empty, so this only fires when that
     * derivation itself failed (`numerosis.domains.central` couldn't be
     * worked out — see `NUMEROSIS_APEX_DOMAIN`/`NUMEROSIS_CENTRAL_DOMAIN` in
     * docs/host-requirements.md) or a host set an empty array explicitly.
     */
    private function verifyCentralDomains(): void
    {
        $domains = Config::get('tenancy.central_domains');

        if (! is_array($domains) || $domains === []) {
            $this->failures[] = "config('tenancy.central_domains') must list at least one hostname — Numerosis::routes() registers one route group per entry, so an empty list means every central URL 404s with no route registered at all.";
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
     *
     * `HostConfig::databaseLockOptions()` already mirrors
     * `innodb_lock_wait_timeout` next to `lock_wait_timeout` on every boot
     * whenever it finds the same pattern this checks for, so this only fires
     * when a host's option string doesn't match that pattern (unusual
     * formatting) rather than for the common case.
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

    /**
     * `HostConfig::centralDatabaseConnection()` already clones
     * `database.connections.{database.default}` into `central` on every
     * boot whenever that key is absent, so the first check here only fires
     * when `database.default` itself doesn't resolve to a real connection
     * array — a genuinely broken `DB_CONNECTION`, not a missing-config gap.
     * `template_tenant_connection` is unrelated to `HostConfig` (only a
     * multi-server host sets it at all) and stays a real check either way.
     */
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

    /**
     * `HostConfig::sessionDomain()` already sets this from
     * `numerosis.domains.apex` on every boot whenever it's null, so this
     * only fires when that derivation itself failed.
     */
    private function verifySessionDomain(): void
    {
        $domain = Config::get('session.domain');

        if (! is_string($domain) || ! str_starts_with($domain, '.')) {
            $this->failures[] = "config('session.domain') must start with a leading dot (e.g. '.example.com') — see docs/host-requirements.md's config/session.php row.";
        }
    }

    /**
     * `numerosis.auth.guards.tenant` defaults to `'tenant'`
     * (`config/numerosis.php`, Phase 0 of `better-dx.md`) and
     * `HostConfig::tenantAuthGuard()` creates that guard whenever it's
     * absent — `central` needs no equivalent, since it names Laravel's own
     * stock `'web'` guard, always present. This only fires when a host has
     * pointed either key at a name that isn't a real guard.
     */
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

    /**
     * `Password::sendResetLink()` resolves its user model through the broker
     * config, not through `auth.providers`. With no broker entry Laravel falls
     * back to its own generic `Illuminate\Foundation\Auth\User`, which has no
     * `Notifiable` trait — so the failure is `Call to undefined method
     * ...User::notify()`, reading as a broken model rather than missing config.
     *
     * `HostConfig::authPasswordBroker()` already creates the broker entry
     * whenever `auth.defaults.passwords` names one that's missing; Laravel's
     * own stock config always sets `auth.defaults.passwords` itself, so the
     * first failure branch below is a genuinely broken host, not a missing
     * default.
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
     *
     * `HostConfig::numerosisConfig()` deep-fills `numerosis.*` from the
     * package's own defaults (which include this key as its 5-provider
     * metadata block) on every boot, so this only fires when a host has set
     * the key itself to something that isn't an array.
     */
    private function verifySocialProviders(): void
    {
        if (! is_array(Config::get('numerosis.social.providers'))) {
            $this->failures[] = "config('numerosis.social.providers') must be an array (use [] when SocialLoginFeature is off) — a missing key throws InvalidArgumentException from whichever view renders the social-login buttons.";
        }
    }

    /**
     * Both names are read *inside* a `route()` call, so an unset key becomes
     * `route(null)` and the host sees `Route [] not defined` raised from a
     * Blade view — naming neither the config key nor the route it wanted.
     *
     * Same `HostConfig::numerosisConfig()` deep-fill as `verifySocialProviders()`
     * above covers these two keys' own package defaults.
     */
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

    /**
     * The insert is done by `queue:work`'s own JobFailed listener, so a
     * connection with no `failed_jobs` table does not lose a log line — it
     * throws inside the worker, from framework code, naming neither this key
     * nor the table.
     *
     * `HostConfig::failedJobsConnection()` already sets `queue.failed.database`
     * to `'central'` on every boot whenever it's still riding
     * `database.default`, so the "must name a connection" branch only fires
     * for a genuinely broken `database.default`. The "has no failed_jobs
     * table" branch stays fully real either way — that needs a migration to
     * have actually run, which no boot-time normalization can do.
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
     * It was reachable one way that `HostConfig::numerosisConfig()` has since
     * closed: a host holding a **published** copy of config/numerosis.php
     * from before these keys existed, where Laravel's `mergeConfigFrom()`
     * merges only one level deep so the host's older `domains` array would
     * win wholesale — the same silent-loss shape D13 documents for the
     * deleted numerosis-billing/tenancy config files. `HostConfig` now
     * deep-fills any *missing* key at every depth of `numerosis.*` on every
     * boot, so that specific staleness no longer reaches here. What remains
     * reachable: a host that set one of these keys to `''` explicitly (a
     * present-but-empty key isn't "missing," so the deep-fill leaves it
     * alone) — the failures that produces are worth naming either way:
     * `Config::string()` throws on a missing key rather than defaulting,
     * taking out tenant creation and both panel domain screens; and
     * `Route::domain(null)` is a *getter* branch returning the route's
     * current domain string instead of `$this`, so routes/auth.php fails one
     * line later as "Call to a member function name() on string".
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
     * not the default path.
     *
     * `HostConfig::tenantMigrationParameters()` already appends the vendor
     * path (and forces `--realpath` true) on every boot, unconditionally —
     * it runs inside `packageRegistered()`, before this command's `handle()`
     * is ever reached, and nothing between the two touches this key. So the
     * vendor path being present is no longer something a host can fail to
     * wire; what remains genuinely host-owned is any *additional* path a
     * host has appended for its own migrations — this validates those are
     * well-formed, the same shape of check a duplicated, drifting published
     * copy needed to be caught by before.
     *
     * Does **not** check the extra path actually exists on disk. Stancl's
     * own stock `config/tenancy.php` (`vendor/stancl/tenancy/assets/config.php`)
     * ships `'--path' => [database_path('migrations/tenant')]` by default —
     * a conventional location for a host's own tenant migrations, present
     * whether or not the host has ever put anything there. A host running
     * on the package's tenant migrations alone (no custom ones) legitimately
     * has no such directory; flagging that as broken was a false positive
     * discovered while trimming thin-app's `config/tenancy.php` down to only
     * its genuine customisations (Phase 6 of `better-dx.md`) — the very
     * first host with no `database/migrations/tenant` directory tripped it.
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
     * `numerosis-assets` is a deliberate publish, not a mistake (see
     * NumerosisServiceProvider's comment on why resources/{css,js} have to
     * land at resource_path() directly), so a host is allowed to customise
     * the published copy. But two of the shipped JS files (stripe-checkout.js,
     * stripe-confirm.js, imported by numerosis.js by relative path) are
     * load-bearing for payment, so silent drift between the vendor original
     * and a host's copy is worth surfacing — this warns, it does not fail
     * the install.
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
     * `->theme(NumerosisServiceProvider::THEME_ID)` — a Filament `Theme`
     * asset registered against a prebuilt, checked-in CSS file
     * (`dist/filament-theme.css`), not `->viteTheme()`. A host does not
     * touch `vite.config.js` for this at all: `php artisan filament:assets`
     * (already required for Filament's own core CSS to exist) copies it to
     * `public/css/nvade/numerosis/`. This check only fires once that
     * directory tree exists at all — i.e. the host has run
     * `filament:assets` at least once — and confirms our file specifically
     * landed, the same way a missing Filament core CSS file would mean the
     * command was never run. Silent-zero-CSS here reads as a broken deploy,
     * not a missing config line, so this fails loudly.
     *
     * Same check for `dist/numerosis.js`/`dist/numerosis.css`
     * (`NumerosisServiceProvider::ASSET_ID`) — `Numerosis::assetTags()`
     * falls back to these whenever the host hasn't published and built its
     * own `resources/js/numerosis.js`, which is the default, so a missing
     * file here breaks every page that renders
     * `resources/views/partials/styles.blade.php`, not just the panels.
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
     * Two failure shapes remain now that `Numerosis::model()` resolves a
     * published stub by convention (`App\Models\<suffix>`, no config
     * required — see that method's docblock): an explicit
     * `numerosis.models.<FQCN>` naming a class that does not exist or is not
     * a subclass, same as before; and a stub published at the conventional
     * path that `Numerosis::model()` is *not* actually picking up (wrong
     * namespace, or a class that exists but does not extend the package
     * model) — a host would otherwise only discover this the way the
     * pre-convention version of this check existed to prevent: package
     * class-strings written into morph columns, surfacing later as
     * SQLSTATE 1205/1062 on an unrelated insert.
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
            $this->failures[] = 'The central `permissions` table is empty — Spatie throws PermissionDoesNotExist rather than returning false, so every policy check 500s with "There is no permission named …", which reads as a guard bug. Run `php artisan numerosis:install` (seeds by default).';
        }

        if ($schema->getConnection()->table('payment_plans')->count() === 0) {
            $this->failures[] = 'The central `payment_plans` table is empty — the registration wizard has nothing to sell and renders an empty plan step. Run `php artisan numerosis:install` (seeds by default).';
        }
    }

    /**
     * `HostConfig::numerosisConfig()` deep-fills any *missing* key in a
     * published `config/numerosis.php`, at every depth — genuinely safe
     * against a host's file merely being incomplete. It has no way to catch
     * a key the host's file still names with an outdated shape: the key
     * isn't missing, so nothing about the deep-fill notices. This reads
     * `schema_version` straight out of the host's *published file* (via
     * `require`, the same way a fresh boot's own `mergeConfigFrom()` would
     * read it) rather than through `Config::get('numerosis.schema_version')`
     * — that path would already show the package's current value, since an
     * entirely-missing key is exactly what the deep-fill silently backfills,
     * which would make this check pass regardless of how stale the file
     * actually is.
     *
     * A host with no published file at all is unaffected — `config()`
     * already reads the package's own file directly in that case, always
     * current, so there's nothing to compare.
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
     * The 9 models a host may override, each with the path
     * NumerosisServiceProvider publishes its stub to and the class name
     * `Numerosis::model()`'s convention step expects it under. Kept in one
     * place so `verifyModelOverrides()` and `Numerosis::model()` cannot
     * disagree about which models exist or what a stub should be named.
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
        $this->line('  2. Run a queue worker on the dedicated "provisioning" queue (see docker/8.5/supervisord.conf\'s [program:queue-provisioning] in saas-m for the reference config) — tenant provisioning is queued there, not on the default worker.');
        $this->line('  3. Run `php artisan filament:assets` (you likely already run this for Filament itself) — it copies both Filament panels\' theming (colours, radius, Instrument Sans) plus this package\'s prebuilt dist/numerosis.js and dist/numerosis.css to public/{css,js}/nvade/numerosis/. No vite.config.js entry needed for any of it: none of it goes through your build unless you\'ve published and customised resources/js/numerosis.js yourself (Numerosis::assetTags() prefers your own Vite manifest entry for it when one exists).');
        $this->line('  4. To rebrand (accent colour, radius, fonts, density) for the *main app*, add a `:root { --pref-...: ...; }` block to your published resources/css/app.css AFTER its `@import \'.../vendor/nvade/numerosis/resources/css/tokens.css\';` line. See tokens.css for the full list of overridable custom properties. This does not reach either Filament panel — the panel theme from step 3 is a prebuilt file compiled once against the default `--pref-accent-hue` and does not read your app.css (a panel page loads only its own theme stylesheet, nothing else). Rebranding a panel\'s accent means building your own theme CSS (copy resources/theme-src/filament-theme.css from the package as a starting point, edit `--pref-accent-hue`, register it as your own Filament `Theme` asset or `->viteTheme()`) and pointing `->theme()`/`->viteTheme()` at it in your own panel providers instead of Numerosis\'s. This is a one-time, host-level choice either way — Numerosis has no per-user or per-tenant theme picker. A custom `--pref-accent-hue` is not contrast-verified for you — white text on `--color-primary` is only checked against the default hue; check your own hue\'s contrast (browser devtools\' contrast checker is enough) before shipping it.');
    }
}
