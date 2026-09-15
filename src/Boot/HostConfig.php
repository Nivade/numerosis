<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Boot;

use Illuminate\Support\Facades\Config;
use Laravel\Fortify\Features as FortifyFeatures;
use Nvade\Numerosis\Database\Seeders\TenantDatabaseSeeder;
use Nvade\Numerosis\Exceptions\Boot\ConfigNamespaceNotReady;
use Nvade\Numerosis\Features\Auth\PasswordResetFeature;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\Services\Tenancy\AuthGuardBootstrapper;
use Nvade\Numerosis\Services\Tenancy\PasswordBrokerBootstrapper;
use Nvade\Numerosis\Services\Tenancy\SpatiePermissionsBootstrapper;
use Stancl\Tenancy\Database\Models\Domain as StanclDomain;
use Stancl\Tenancy\Database\Models\Tenant as StanclTenant;

/**
 * Fills in the config this package needs, so an app only has to supply
 * ordinary Laravel database credentials to get a working install. Every write
 * is recorded and reported by `numerosis:install`, and running twice is inert.
 */
final class HostConfig
{
    /** @var list<string> */
    private static array $applied = [];

    public static function apply(): void
    {
        self::$applied = [];

        self::tenancyModels();
        self::centralAuthProviderModelPreference();
        self::centralDomains();
        self::tenancyBootstrappers();
        self::tenantMigrationParameters();
        self::tenantSeederPreference();
        self::filesystemDisks();
        self::centralConnectionPreference();
        self::centralDatabaseConnection();
        self::sessionDomain();
        self::authPasswordBroker();
        self::applyCorrections();
        self::fortifyFeatures();
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
        self::assertNamespaceIsReady($key);

        Config::set($key, $value);
        self::$applied[] = $key;
    }

    /**
     * `Arr::set()` replaces a missing intermediate segment wholesale, and the
     * owning package's later one-level-deep `mergeConfigFrom()` then keeps the
     * truncated value over its own complete defaults. Loud here beats one
     * route breaking months later.
     */
    private static function assertNamespaceIsReady(string $key): void
    {
        $segments = explode('.', $key);

        // numerosis.* is populated by this package's own mergeConfigFrom,
        // before anything here runs.
        if ($segments[0] === 'numerosis') {
            return;
        }

        array_pop($segments);
        $namespace = '';

        foreach ($segments as $segment) {
            $namespace = $namespace === '' ? $segment : "{$namespace}.{$segment}";

            if (! is_array(Config::get($namespace))) {
                throw ConfigNamespaceNotReady::for($key, $namespace);
            }
        }
    }

    /** Writes a preference only when it would actually change the key. */
    private static function project(string $key, mixed $value): void
    {
        if (Config::get($key) !== $value) {
            self::set($key, $value);
        }
    }

    /**
     * Points tenancy at this package's tenant, domain and user models.
     * Left alone once any of them names a class of your own — unlike
     * `auth.providers.users.model` below, a host may set one of these four
     * directly rather than through `numerosis.models.*`.
     */
    private static function tenancyModels(): void
    {
        self::applyWhileStock([
            'tenancy.tenant_model' => [[StanclTenant::class], Numerosis::model(Tenant::class)],
            'tenancy.domain_model' => [[StanclDomain::class], Numerosis::model(Domain::class)],

            // The last two are in no stancl config stub, so there is no stock
            // value to compare against and unset is the only signal.
            'tenancy.central_user_model' => [[], Numerosis::model(CentralUser::class)],
            'tenancy.tenant_user_model' => [[], Numerosis::model(TenantUser::class)],
        ]);
    }

    /**
     * Projects `numerosis.models.*`'s `CentralUser` entry onto
     * `auth.providers.users.model`, always.
     */
    private static function centralAuthProviderModelPreference(): void
    {
        self::project('auth.providers.users.model', Numerosis::model(CentralUser::class));
    }

    /**
     * Derives the central domain from `numerosis.domains.central`. Stays a
     * method rather than a plain projection: the vendor key is a list (a
     * host serving several central hostnames sets more than one), so an
     * unconditional projection would flatten that list to one value.
     */
    private static function centralDomains(): void
    {
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
     * Appends the three bootstrappers this package relies on, for the default
     * guard, spatie's permission lookups and Fortify's password broker. A
     * host's own bootstrappers are kept.
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
     * Adds the package's tenant migrations to whatever paths are already
     * configured, keeping yours. `--realpath` is forced on, since the added
     * paths are absolute.
     *
     * The default seed is stancl's own implicit `database/migrations/tenant`,
     * which writing `--path` at all would otherwise discard.
     */
    private static function tenantMigrationParameters(): void
    {
        $parameters = Config::array('tenancy.migration_parameters', []);

        $paths = $parameters['--path'] ?? [database_path('migrations/tenant')];
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
     * Projects `numerosis.tenancy.seeder` onto `tenancy.seeder_parameters`,
     * always — set the numerosis key to choose a seeder, not this one.
     */
    private static function tenantSeederPreference(): void
    {
        $parameters = Config::array('tenancy.seeder_parameters', []);
        $class = Config::string('numerosis.tenancy.seeder', TenantDatabaseSeeder::class);

        if (($parameters['--class'] ?? null) !== $class) {
            self::set('tenancy.seeder_parameters', [...$parameters, '--class' => $class]);
        }
    }

    /**
     * Keeps the `livewire` disk out of the tenant-suffixed list. Livewire's
     * temporary-upload route is never tenant-identified, so suffixing that
     * disk makes validation look for the file in a directory the upload was
     * never written to, surfacing as a bogus "invalid file type" error.
     */
    private static function filesystemDisks(): void
    {
        /** @var list<string> $disks */
        $disks = Config::array('tenancy.filesystem.disks', []);

        if (in_array('livewire', $disks, true)) {
            self::set('tenancy.filesystem.disks', array_values(array_diff($disks, ['livewire'])));
        }
    }

    /**
     * Projects `numerosis.tenancy.central_connection` onto
     * `tenancy.database.central_connection`, always.
     */
    private static function centralConnectionPreference(): void
    {
        self::project(
            'tenancy.database.central_connection',
            Config::string('numerosis.tenancy.central_connection', 'central'),
        );
    }

    /**
     * Defines the `central` connection by cloning `database.default`, so
     * ordinary Laravel database credentials are all an install needs. An
     * existing `central` connection is never overwritten.
     */
    private static function centralDatabaseConnection(): void
    {
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
     * Scopes the session cookie to the apex domain, so one session spans the
     * central app and every tenant subdomain. Left null, each subdomain gets
     * its own separate session. The null check stays even though the value
     * is a preference: overwriting a host's own `session.domain` /
     * `SESSION_DOMAIN` unconditionally would break login silently.
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
     * Defines the default password broker against the `users` provider.
     * `Password::sendResetLink()` resolves its model through the broker and
     * never through `auth.providers`, so with no broker entry it falls back to
     * a model that cannot be notified. That surfaces as "Call to undefined
     * method ...User::notify()", giving no hint that config is missing.
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
     * Guarded key => value corrections: written while a key is unset or
     * still holding a stock value. `tenancy.filesystem.root_override.local`
     * and `queue.failed.database` never resolve to null, so a plain null
     * check would silently stop correcting them once a host's other config
     * happened to match their vendor default.
     *
     * @return array<string, array{0: list<mixed>, 1: mixed}>
     */
    private static function corrections(): array
    {
        return [
            'auth.guards.tenant' => [[], [
                'driver' => 'session',
                'provider' => 'tenant',
            ]],
            'auth.providers.tenant' => [[], [
                'driver' => 'eloquent',
                'model' => Numerosis::model(TenantUser::class),
            ]],
            'auth.passwords.tenant' => [[], [
                'provider' => 'tenant',
                'table' => 'password_reset_tokens',
                'expire' => 60,
                'throttle' => 60,
            ]],
            'activitylog.table_name' => [[], 'activity_log'],

            // Corrects stancl's stock tenant root, which predates Laravel 11
            // moving the `local` disk to `storage/app/private`.
            'tenancy.filesystem.root_override.local' => [['%storage_path%/app/'], '%storage_path%/app/private/'],

            'queue.failed.database' => [[Config::get('database.default')], 'central'],
        ];
    }

    private static function applyCorrections(): void
    {
        self::applyWhileStock(self::corrections());
    }

    /**
     * Writes each key while it is unset or still holds one of the stock values
     * listed against it, leaving a host's own value alone.
     *
     * @param  array<string, array{0: list<mixed>, 1: mixed}>  $table
     */
    private static function applyWhileStock(array $table): void
    {
        foreach ($table as $key => [$stockValues, $value]) {
            $current = Config::get($key);

            if ($current === null || in_array($current, $stockValues, true)) {
                self::set($key, $value);
            }
        }
    }

    /**
     * The auth screens Fortify registers, dropping two-factor and passkeys,
     * which have neither views nor columns here. Written only while
     * `numerosis.auth.manage_fortify_features` is true; set it false once you
     * have edited `fortify.features` yourself.
     */
    private static function fortifyFeatures(): void
    {
        if (! Config::boolean('numerosis.auth.manage_fortify_features')) {
            return;
        }

        $features = array_values(array_filter([
            FortifyFeatures::registration(),
            FeatureRegistry::enabled(PasswordResetFeature::NAME) ? FortifyFeatures::resetPasswords() : null,
            FortifyFeatures::updateProfileInformation(),
            FortifyFeatures::updatePasswords(),
            FortifyFeatures::emailVerification(),
        ], static fn (?string $feature): bool => $feature !== null));

        if (Config::array('fortify.features') !== $features) {
            self::set('fortify.features', $features);
        }
    }
}
