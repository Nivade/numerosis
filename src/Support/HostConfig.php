<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support;

use Illuminate\Support\Facades\Config;
use Laravel\Fortify\Features as FortifyFeatures;
use Laravel\Fortify\Fortify;
use Nvade\Numerosis\Contracts\Auth\CentralUserModel;
use Nvade\Numerosis\Database\Seeders\TenantDatabaseSeeder;
use Nvade\Numerosis\Features\Auth\PasswordResetFeature;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Services\Tenancy\Bootstrappers\AuthGuardBootstrapper;
use Nvade\Numerosis\Services\Tenancy\Bootstrappers\PasswordBrokerBootstrapper;
use Nvade\Numerosis\Services\Tenancy\Bootstrappers\SpatiePermissionsBootstrapper;
use ReflectionClass;
use Stancl\Tenancy\Database\Models\Domain as StanclDomain;
use Stancl\Tenancy\Database\Models\Tenant as StanclTenant;

/**
 * Fills in the config this package needs, so an app only has to supply
 * ordinary Laravel database credentials to get a working install.
 *
 * Nothing here overrides a deliberate choice. Each key is only written when
 * it is unset or still holds the stock value shipped by Laravel or
 * stancl/tenancy — several of those never resolve to null, so "untouched"
 * has to be judged against the stock value rather than against null.
 * Anything you set yourself is left alone.
 *
 * Every key actually written is recorded and reported by `numerosis:install`,
 * so you can see what was configured for you without diffing defaults by
 * hand. Running twice changes nothing the second time.
 */
final class HostConfig
{
    /** @var list<string> */
    private static array $applied = [];

    public static function apply(): void
    {
        self::$applied = [];

        self::tenancyModels();
        self::centralDomains();
        self::tenancyBootstrappers();
        self::tenantMigrationParameters();
        self::tenantSeederParameters();
        self::livewireDiskExclusion();
        self::filesystemRootOverride();
        self::tenancyCentralConnection();
        self::centralDatabaseConnection();
        self::databaseLockOptions();
        self::sessionDomain();
        self::failedJobsConnection();
        self::tenantAuthGuard();
        self::tenantAuthProvider();
        self::centralAuthProviderModel();
        self::authPasswordBroker();
        self::tenantPasswordBroker();
        self::activityLogTable();
        self::fortifyFeatures();
        self::numerosisConfig();
    }

    /**
     * Config keys the most recent {@see self::apply()} actually changed.
     *
     * @return list<string>
     */
    public static function applied(): array
    {
        return self::$applied;
    }

    private static function set(string $key, mixed $value): void
    {
        Config::set($key, $value);
        self::$applied[] = $key;
    }

    /**
     * Points tenancy at this package's tenant, domain and user models.
     * Left alone once any of them names a class of your own.
     */
    private static function tenancyModels(): void
    {
        /**
         * key => [stock value, package default]. **Both keys move on
         * dev-master** — `tenancy.models.{tenant,domain}`, two segments
         * under a sub-array stancl's own `mergeConfigFrom()` populates, so
         * a dotted `Config::set()` would then hit the `Arr::set()`
         * auto-vivification hazard `.ai/rules/package-host-bootstrap.md`
         * documents for `tenancy.database`. See
         * `.ai/rules/stancl-tenancy-v4.md`'s config-key map.
         */
        $stancl = [
            'tenancy.tenant_model' => [StanclTenant::class, Numerosis::model(Tenant::class)],
            'tenancy.domain_model' => [StanclDomain::class, Numerosis::model(Domain::class)],
        ];

        foreach ($stancl as $key => [$stock, $default]) {
            $current = Config::get($key);

            if ($current === null || $current === $stock) {
                self::set($key, $default);
            }
        }

        // This package's own keys, in no stancl config stub.
        // No "stock value" to compare against here (unlike tenant_model/
        // domain_model above, which start out pointed at stancl's own
        // classes) — unset is the only signal.
        $ownKeys = [
            'tenancy.central_user_model' => Numerosis::model(CentralUser::class),
            'tenancy.tenant_user_model' => Numerosis::model(TenantUser::class),
        ];

        foreach ($ownKeys as $key => $default) {
            if (Config::get($key) === null) {
                self::set($key, $default);
            }
        }
    }

    /**
     * Derives the central domain from `APP_URL` (override with
     * `NUMEROSIS_CENTRAL_DOMAIN`). Central routes are bound per hostname
     * listed here, so leaving stancl's stock `127.0.0.1`/`localhost` in
     * place would scope every central URL to the wrong host.
     */
    private static function centralDomains(): void
    {
        /** @var list<string> $stock */
        $stock = ['127.0.0.1', 'localhost'];
        $key = 'tenancy.central_domains';
        $domains = Config::array($key, []);

        if ($domains !== [] && $domains !== $stock) {
            return;
        }

        $central = Config::string('numerosis.domains.central', '');

        if ($central !== '') {
            self::set($key, [$central]);
        }
    }

    /**
     * Appends the three bootstrappers this package relies on:
     * `AuthGuardBootstrapper` switches the default guard to match the
     * current context, `SpatiePermissionsBootstrapper` keeps role and
     * permission lookups pointed at the right database, and
     * `PasswordBrokerBootstrapper` points Fortify's password broker at the
     * tenant one. Your own bootstrappers are kept.
     */
    private static function tenancyBootstrappers(): void
    {
        /** @var list<class-string> $bootstrappers */
        $bootstrappers = Config::array('tenancy.bootstrappers', []);

        $missing = array_values(array_diff(
            [
                SpatiePermissionsBootstrapper::class,
                AuthGuardBootstrapper::class,
                PasswordBrokerBootstrapper::class,
            ],
            $bootstrappers,
        ));

        if ($missing !== []) {
            self::set('tenancy.bootstrappers', [...$bootstrappers, ...$missing]);
        }
    }

    /**
     * Adds the package's tenant migrations — plus any registered via
     * {@see Numerosis::addTenantMigrationPath()} — to whatever paths are
     * already configured, keeping yours. `--realpath` is forced on, since
     * the added paths are absolute.
     */
    private static function tenantMigrationParameters(): void
    {
        /** @var array<string, mixed> $parameters */
        $parameters = Config::array('tenancy.migration_parameters', []);

        $paths = $parameters['--path'] ?? [];
        $paths = is_array($paths) ? array_values($paths) : [];

        $changed = false;

        foreach (Numerosis::tenantMigrationPaths() as $vendorPath) {
            if (! in_array($vendorPath, $paths, true)) {
                $paths[] = $vendorPath;
                $changed = true;
            }
        }

        if (($parameters['--realpath'] ?? null) !== true) {
            $changed = true;
        }

        if ($changed) {
            self::set('tenancy.migration_parameters', [
                ...$parameters,
                '--path' => $paths,
                '--realpath' => true,
            ]);
        }
    }

    /**
     * Seeds new tenant databases with this package's tenant seeder instead
     * of stancl's stock `DatabaseSeeder`, which is your central-database
     * root seeder and knows nothing about tenant data.
     */
    private static function tenantSeederParameters(): void
    {
        /** @var array<string, mixed> $parameters */
        $parameters = Config::array('tenancy.seeder_parameters', []);
        $class = $parameters['--class'] ?? null;

        if ($class === null || $class === 'DatabaseSeeder') {
            self::set('tenancy.seeder_parameters', [
                ...$parameters,
                '--class' => TenantDatabaseSeeder::class,
            ]);
        }
    }

    /**
     * Keeps the `livewire` disk out of the tenant-suffixed list. Livewire's
     * temporary-upload route is never tenant-identified, so suffixing that
     * disk makes validation look for the file in a directory the upload was
     * never written to — surfacing as a bogus "invalid file type" error.
     */
    private static function livewireDiskExclusion(): void
    {
        /** @var list<string> $disks */
        $disks = Config::array('tenancy.filesystem.disks', []);

        if (in_array('livewire', $disks, true)) {
            self::set('tenancy.filesystem.disks', array_values(array_diff($disks, ['livewire'])));
        }
    }

    /**
     * Corrects stancl's stock tenant root for the `local` disk, which
     * predates Laravel 11 moving that disk to `storage/app/private`.
     */
    private static function filesystemRootOverride(): void
    {
        $current = Config::get('tenancy.filesystem.root_override.local');

        if ($current === null || $current === '%storage_path%/app/') {
            self::set('tenancy.filesystem.root_override.local', '%storage_path%/app/private/');
        }
    }

    /**
     * Names the central connection `central`, which is what this package
     * assumes throughout. Skipped once it names anything other than
     * `database.default` — stancl's stock value resolves to `DB_CONNECTION`,
     * so matching the default means it was never chosen deliberately.
     */
    private static function tenancyCentralConnection(): void
    {
        $current = Config::get('tenancy.database.central_connection');
        $default = Config::get('database.default');

        if ($current === null || $current === $default) {
            self::set('tenancy.database.central_connection', 'central');
        }
    }

    /**
     * Defines the `central` connection by cloning `database.default`, so
     * ordinary Laravel database credentials are all an install needs. An
     * existing `central` connection is never overwritten.
     */
    private static function centralDatabaseConnection(): void
    {
        /** @var array<string, mixed> $connections */
        $connections = Config::array('database.connections', []);

        if (array_key_exists('central', $connections)) {
            return;
        }

        $default = Config::string('database.default', '');
        $connection = $connections[$default] ?? null;

        if (is_array($connection)) {
            self::set('database.connections.central', $connection);
        }
    }

    /**
     * Mirrors any MySQL `lock_wait_timeout` you set into
     * `innodb_lock_wait_timeout`. The first bounds waits on schema locks
     * only; without the second, a blocked `INSERT` or `DELETE` still waits
     * out MySQL's 50-second default while appearing to be bounded.
     */
    private static function databaseLockOptions(): void
    {
        /** @var array<string, mixed> $connections */
        $connections = Config::array('database.connections', []);
        $changed = false;

        foreach ($connections as $name => $connection) {
            if (! is_array($connection) || ($connection['driver'] ?? null) !== 'mysql') {
                continue;
            }

            /** @var array<int|string, mixed> $options */
            $options = is_array($connection['options'] ?? null) ? $connection['options'] : [];
            $updatedOptions = $options;

            foreach ($options as $optionKey => $optionValue) {
                if (! is_string($optionValue) || ! str_contains($optionValue, 'lock_wait_timeout')) {
                    continue;
                }

                if (str_contains($optionValue, 'innodb_lock_wait_timeout')) {
                    continue;
                }

                if (preg_match('/(?<!innodb_)lock_wait_timeout\s*=\s*(\d+)/', $optionValue, $matches) !== 1) {
                    continue;
                }

                $updatedOptions[$optionKey] = rtrim($optionValue, '; ')
                    .", innodb_lock_wait_timeout = {$matches[1]}";
                $changed = true;
            }

            if ($updatedOptions !== $options) {
                $connection['options'] = $updatedOptions;
                $connections[$name] = $connection;
            }
        }

        if ($changed) {
            self::set('database.connections', $connections);
        }
    }

    /**
     * Scopes the session cookie to the apex domain, so one session spans the
     * central app and every tenant subdomain. Left null, each subdomain gets
     * its own separate session.
     */
    private static function sessionDomain(): void
    {
        if (Config::get('session.domain') === null) {
            $apex = Config::string('numerosis.domains.apex', '');

            if ($apex !== '') {
                self::set('session.domain', '.'.$apex);
            }
        }
    }

    /**
     * Pins failed jobs to the central connection. Left on a connection that
     * moves under tenancy, a job failing inside tenant context writes its
     * `failed_jobs` row into that tenant's database, where nothing looks
     * for it.
     */
    private static function failedJobsConnection(): void
    {
        $current = Config::get('queue.failed.database');
        $default = Config::get('database.default');

        if ($current === null || $current === $default) {
            self::set('queue.failed.database', 'central');
        }
    }

    /**
     * Adds the `tenant` session guard, paired with the `tenant` provider
     * defined below. Laravel's stock `config/auth.php` ships neither.
     */
    private static function tenantAuthGuard(): void
    {
        if (Config::get('auth.guards.tenant') === null) {
            self::set('auth.guards.tenant', [
                'driver' => 'session',
                'provider' => 'tenant',
            ]);
        }
    }

    private static function tenantAuthProvider(): void
    {
        if (Config::get('auth.providers.tenant') === null) {
            self::set('auth.providers.tenant', [
                'driver' => 'eloquent',
                'model' => Numerosis::model(TenantUser::class),
            ]);
        }
    }

    /**
     * Points central auth at a user model that implements
     * {@see CentralUserModel}, which Laravel's stock `App\Models\User` does
     * not. Any model of yours that satisfies the contract is left alone.
     */
    private static function centralAuthProviderModel(): void
    {
        $model = Config::get('auth.providers.users.model');

        $valid = is_string($model) && class_exists($model) && is_a($model, CentralUserModel::class, true);

        if (! $valid) {
            self::set('auth.providers.users.model', Numerosis::model(CentralUser::class));
        }
    }

    /**
     * Defines the default password broker against the `users` provider.
     * `Password::sendResetLink()` resolves its model through the broker
     * rather than through `auth.providers`, and with no broker entry falls
     * back to a model that cannot be notified — reported as "Call to
     * undefined method ...User::notify()" rather than as missing config.
     */
    private static function authPasswordBroker(): void
    {
        $broker = Config::get('auth.defaults.passwords');

        if (! is_string($broker) || $broker === '') {
            return;
        }

        if (Config::get("auth.passwords.{$broker}") !== null) {
            return;
        }

        self::set("auth.passwords.{$broker}", [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ]);
    }

    /**
     * Fortify's password-reset routes load inside the tenant group too
     * (`Support\Numerosis::loadFortifyRoutes()`), and `Password::broker()`
     * resolves a model through `auth.passwords.*`, not `auth.guards.*` — the
     * central broker {@see self::authPasswordBroker()} defines points at the
     * `users` provider (`CentralUser`), which would resolve the wrong model
     * for a tenant subdomain's reset request. A dedicated `tenant` broker
     * against the `tenant` provider is what `fortify.passwords` is swapped
     * to for that group.
     */
    private static function tenantPasswordBroker(): void
    {
        if (Config::get('auth.passwords.tenant') !== null) {
            return;
        }

        self::set('auth.passwords.tenant', [
            'provider' => 'tenant',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ]);
    }

    /**
     * Names the activity-log table. Current spatie/laravel-activitylog
     * ships no default for it, and the activity-log migrations read the key
     * directly — unset, they fail with `Incorrect table name ''`.
     */
    private static function activityLogTable(): void
    {
        if (Config::get('activitylog.table_name') === null) {
            self::set('activitylog.table_name', 'activity_log');
        }
    }

    /**
     * The auth screens Fortify should register, while `fortify.features`
     * still holds Fortify's own shipped list — i.e. while the host has not
     * chosen. Two differences from that shipped list:
     *
     * - two-factor authentication and passkeys are dropped. Neither has a
     *   view under `numerosis::auth.` and neither has the columns their
     *   controllers write, so leaving them on registers screens that fail
     *   only once somebody reaches them.
     * - password reset follows `PasswordResetFeature`, this package's own
     *   toggle, so the two configs cannot disagree about whether the
     *   feature exists.
     *
     * A host that publishes `config/fortify.php` and edits the list owns it
     * outright from then on, including turning 2FA back on — which is what
     * `docs/extending.md` promises and what setting this key unconditionally
     * in `registerFortify()` used to quietly break.
     */
    private static function fortifyFeatures(): void
    {
        if (Config::array('fortify.features') !== self::fortifyStockFeatures()) {
            return;
        }

        self::set('fortify.features', array_values(array_filter([
            FortifyFeatures::registration(),
            Features::enabled(PasswordResetFeature::NAME) ? FortifyFeatures::resetPasswords() : null,
            FortifyFeatures::updateProfileInformation(),
            FortifyFeatures::updatePasswords(),
            FortifyFeatures::emailVerification(),
        ])));
    }

    /**
     * Fortify's own shipped `features` list, read from the package rather
     * than restated here — restating it would go stale the first time
     * Fortify added a feature, and "the host has not chosen" would silently
     * become "the host has chosen" for every install.
     *
     * @return list<mixed>
     */
    private static function fortifyStockFeatures(): array
    {
        $path = dirname((string) (new ReflectionClass(Fortify::class))->getFileName(), 2).'/config/fortify.php';

        if (! is_file($path)) {
            return [];
        }

        /** @var array<string, mixed> $stock */
        $stock = require $path;

        $features = $stock['features'] ?? [];

        return is_array($features) ? array_values($features) : [];
    }

    /**
     * Backfills `config/numerosis.php` defaults at every depth. Laravel
     * merges published config only one level deep, so overriding a single
     * nested key such as `billing.trial_days` would otherwise drop every
     * sibling under `billing`.
     *
     * Only keyed arrays are filled. Lists such as `features` are left
     * exactly as you set them, including empty, since a list's meaning is
     * its contents and order rather than which keys are present.
     */
    private static function numerosisConfig(): void
    {
        /** @var array<string, mixed> $packageDefaults */
        $packageDefaults = require dirname(__DIR__, 2).'/config/numerosis.php';

        /** @var array<string, mixed> $current */
        $current = Config::array('numerosis', []);

        $merged = self::fillMissingKeys($packageDefaults, $current);

        if ($merged !== $current) {
            self::set('numerosis', $merged);
        }
    }

    /**
     * @param  array<array-key, mixed>  $default
     * @param  array<array-key, mixed>  $current
     * @return array<array-key, mixed>
     */
    private static function fillMissingKeys(array $default, array $current): array
    {
        $merged = $current;

        foreach ($default as $key => $value) {
            if (! array_key_exists($key, $current)) {
                $merged[$key] = $value;

                continue;
            }

            $existing = $current[$key];

            if (is_array($value) && is_array($existing) && ! array_is_list($value) && ! array_is_list($existing)) {
                $merged[$key] = self::fillMissingKeys($value, $existing);
            }
        }

        return $merged;
    }
}
