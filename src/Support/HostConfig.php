<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support;

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Contracts\Auth\CentralUserModel;
use Nvade\Numerosis\Database\Seeders\TenantDatabaseSeeder;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Services\Tenancy\Bootstrappers\AuthGuardBootstrapper;
use Nvade\Numerosis\Services\Tenancy\Bootstrappers\SpatiePermissionsBootstrapper;
use Stancl\Tenancy\Database\Models\Domain as StanclDomain;
use Stancl\Tenancy\Database\Models\Tenant as StanclTenant;

/**
 * Boot-time config normalization: fills in every host config key this
 * package needs, but only when the host hasn't set one itself or is still
 * carrying an upstream package's stock default that predates this one's
 * needs. "Host hasn't set one itself" is judged against that stock value,
 * not against null — several of these keys (Laravel's own `auth.php`,
 * stancl's merged `tenancy.php`) always resolve to *something*, so a host
 * that changed nothing still reads as "set."
 *
 * Called first thing from `NumerosisServiceProvider::packageRegistered()` —
 * same phase as the Livewire/filesystem defaults that method already sets,
 * for the same reason: some of what those entries touch (Livewire's own
 * config) is read eagerly during `boot()`, so anything later than
 * `register()` is too late for every provider regardless of discovery
 * order. `config('numerosis.*')` is already merged with any host override
 * by the time this runs — `PackageServiceProvider::register()` calls
 * `registerPackageConfigs()` before `packageRegistered()` — so entries here
 * may read it directly.
 *
 * Every private method below follows one rule: set the key only when
 * changing it would actually change something, and record every key it
 * touched into `$applied` — `numerosis:install` surfaces that list so a host
 * knows what got configured for them rather than having to diff against
 * every default by hand. Idempotent by construction: a second `apply()`
 * against already-normalized config touches nothing and reports nothing.
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
        self::numerosisConfig();
    }

    /**
     * Config keys `apply()` actually changed on its most recent run — a
     * key set to the value it already had is not "applied."
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
     * `tenant_model`/`domain_model` are stancl's own keys — a host that
     * hasn't published `config/tenancy.php` (or published it and left these
     * untouched) still reads stancl's stock class here, not null.
     * `central_user_model`/`tenant_user_model` are this package's own
     * invented keys with no stancl stock value, so "unset" is the only
     * stale state for them. See `.claude/rules/tenant-provisioning.md` (the
     * non-fillable `id` trap) for why an unresolvable `tenant_model` fails
     * silently rather than loudly — this is what stops that.
     */
    private static function tenancyModels(): void
    {
        $map = [
            'tenancy.tenant_model' => [StanclTenant::class, Numerosis::model(Tenant::class)],
            'tenancy.domain_model' => [StanclDomain::class, Numerosis::model(Domain::class)],
            'tenancy.central_user_model' => [null, Numerosis::model(CentralUser::class)],
            'tenancy.tenant_user_model' => [null, Numerosis::model(TenantUser::class)],
        ];

        foreach ($map as $key => [$stock, $default]) {
            $current = Config::get($key);

            if ($current === null || $current === $stock) {
                self::set($key, $default);
            }
        }
    }

    /**
     * `Numerosis::routes()` registers one central route group per entry
     * here — an empty list 404s every central URL with no route registered
     * at all. `numerosis.domains.central` is already this same value
     * (derived from `APP_URL`, overridable via `NUMEROSIS_CENTRAL_DOMAIN`),
     * so this reuses it rather than re-deriving it a second way.
     */
    private static function centralDomains(): void
    {
        $domains = Config::array('tenancy.central_domains', []);

        if ($domains !== []) {
            return;
        }

        $central = Config::string('numerosis.domains.central', '');

        if ($central !== '') {
            self::set('tenancy.central_domains', [$central]);
        }
    }

    /**
     * `AuthGuardBootstrapper` is the entire enforcement mechanism for
     * "central domain = central guard, inside tenant = tenant guard" (see
     * `.claude/rules/auth-guards.md`); `SpatiePermissionsBootstrapper`
     * keeps Spatie's guard resolution correct under tenancy. Appended, not
     * replaced — a host's own bootstrapper list (stancl's stock four, or a
     * host's customised list) keeps whatever it already has.
     */
    private static function tenancyBootstrappers(): void
    {
        /** @var list<class-string> $bootstrappers */
        $bootstrappers = Config::array('tenancy.bootstrappers', []);

        $missing = array_values(array_diff(
            [SpatiePermissionsBootstrapper::class, AuthGuardBootstrapper::class],
            $bootstrappers,
        ));

        if ($missing !== []) {
            self::set('tenancy.bootstrappers', [...$bootstrappers, ...$missing]);
        }
    }

    /**
     * The package's own tenant migrations must run from the vendor path
     * itself (`Numerosis::tenantMigrationPath()`), not a published copy —
     * see that method's docblock. Appends the vendor path to whatever
     * `--path` list already exists (stancl's stock single entry, or a
     * host's customised list) rather than replacing it, and forces
     * `--realpath` true — required for an absolute vendor path to resolve
     * at all, and the one flag a host could plausibly have turned off
     * without realising it broke this.
     */
    private static function tenantMigrationParameters(): void
    {
        /** @var array<string, mixed> $parameters */
        $parameters = Config::array('tenancy.migration_parameters', []);

        $paths = $parameters['--path'] ?? [];
        $paths = is_array($paths) ? array_values($paths) : [];

        $vendorPath = Numerosis::tenantMigrationPath();
        $changed = false;

        if (! in_array($vendorPath, $paths, true)) {
            $paths[] = $vendorPath;
            $changed = true;
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
     * Stancl's stock `--class` is the literal string `'DatabaseSeeder'` —
     * the host's own root seeder, which knows nothing about tenant data.
     * Redirected to this package's own tenant seeder whenever the host
     * hasn't named something else.
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
     * See `.claude/rules/tenant-filesystem.md`: the `livewire` disk must
     * never be tenant-suffixed — Livewire's upload route is never
     * tenant-identified, so a suffixed root disagrees with where the
     * upload actually lands. This only ever fires for a host that copied
     * an example listing every disk, `livewire` included.
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
     * Stancl's stock `local` override predates Laravel 11's private-by-
     * default local disk — see `.claude/rules/tenant-filesystem.md`. Fixed
     * the same way that rule's incident was: point at
     * `app/private/` instead of `app/`.
     */
    private static function filesystemRootOverride(): void
    {
        $current = Config::get('tenancy.filesystem.root_override.local');

        if ($current === null || $current === '%storage_path%/app/') {
            self::set('tenancy.filesystem.root_override.local', '%storage_path%/app/private/');
        }
    }

    /**
     * Stancl's stock value is `env('DB_CONNECTION', 'central')` — on a
     * fresh Laravel skeleton that resolves to whatever `DB_CONNECTION` is
     * (typically `mysql`), not the literal `'central'` this package's own
     * fallbacks assume everywhere (`.claude/rules/testing.md`). Normalized
     * only when the value still matches `database.default` — i.e. still
     * riding that coincidence — so a host that deliberately named its
     * central connection something else keeps it.
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
     * A host that has never heard of this package's `central`/`tenant`
     * connection split still has a working `database.default` connection —
     * cloning it under the `central` name is what lets `numerosis:install`
     * ask for nothing beyond ordinary Laravel database credentials. Never
     * overwrites an existing `central` entry.
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
     * See `.claude/rules/testing.md`'s `innodb_lock_wait_timeout` bullet: a
     * host that bounds `lock_wait_timeout` (metadata/DDL locks) but not
     * `innodb_lock_wait_timeout` (ordinary row/FK locks) believes it has
     * bounded lock waits and has not — a blocked `INSERT`/`DELETE` still
     * waits out MySQL's 50s default. Mirrors the same numeric value into
     * the same `SET SESSION` string rather than requiring a second
     * connection edit.
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
     * Left null, `SessionServiceProvider` scopes the session cookie to a
     * single host — every tenant subdomain would then hold its own,
     * separate session from the central app instead of sharing one
     * cookie across `*.numerosis.domains.apex`.
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
     * See `.claude/rules/exception-handling.md`: gated by
     * `QUEUE_FAILED_DRIVER`, not `queue.default` — Laravel's stock value is
     * `env('DB_CONNECTION', ...)`, the same coincidental value
     * `tenancyCentralConnection()` above corrects. A connection name that
     * moves under tenancy means a failed-job row inserted while a tenant
     * connection is active never lands in the `failed_jobs` table
     * `queue:work`'s own listener expects.
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
     * The tenant guard Laravel's own `config/auth.php` has no reason to
     * ship — a host wiring nothing beyond database credentials still gets
     * one, paired with `tenantAuthProvider()` below via the shared
     * `'tenant'` provider name.
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
     * Laravel's stock `auth.providers.users.model` is its own generic
     * `App\Models\User`, which implements neither `SyncMaster` nor this
     * package's `CentralUserModel` — every central-auth call site needs a
     * model that does. Left alone the moment a host points this at
     * something that already satisfies the contract, whether that's a
     * published stub or a hand-written subclass.
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
     * `Password::sendResetLink()` resolves its user model through this
     * broker config, not through `auth.providers` directly — with no
     * broker entry Laravel falls back to its own generic
     * `Illuminate\Foundation\Auth\User`, which has no `Notifiable` trait,
     * so the failure reads as "Call to undefined method
     * ...User::notify()" rather than as missing config. Paired against the
     * `'users'` provider `centralAuthProviderModel()` above keeps correct.
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
     * `mergeConfigFrom()` merges `numerosis.*` exactly one level deep
     * (Laravel's own `array_merge(package, host)`), so a host that
     * publishes `config/numerosis.php` and overrides only
     * `modules.catalogue` silently loses `modules.plugins` — the host's
     * `modules` array replaces the package's wholesale, since `array_merge`
     * only ever looks at top-level keys. This fills anything still missing
     * at every depth, but only inside associative (keyed) arrays — a list
     * like `features` is left exactly as the host set it, even to `[]`,
     * because a list's meaning is its full contents and order, not "which
     * keys are present."
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
