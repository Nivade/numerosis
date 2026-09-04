<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy\Bootstrappers;

use Illuminate\Contracts\Config\Repository;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Points `fortify.passwords` at the tenant password broker for the duration
 * of tenancy, the way {@see AuthGuardBootstrapper} does for the default
 * guard.
 *
 * Fortify resolves its broker at **request** time —
 * `Password::broker(config('fortify.passwords'))` in
 * `PasswordResetLinkController::broker()`, `NewPasswordController::broker()`
 * and `PasswordController::broker()` — and a broker resolves its user model
 * through `auth.passwords.*`, not through `auth.guards.*`. So a tenant
 * subdomain's forgot/reset request would otherwise look the address up in
 * the central `users` provider (`CentralUser`): either "we can't find a user
 * with that email" for a tenant-only account, or a reset link for a
 * same-addressed central account, with nothing logged either way.
 *
 * **This cannot be done where the guard is done.** `Numerosis::routes()`
 * swaps `fortify.guard` around its `require` of Fortify's route file because
 * `routes/routes.php` bakes `'guest:'.config('fortify.guard')` into route
 * middleware at registration time. Nothing bakes the broker: the three
 * controllers above read the key when the request arrives, by which time the
 * `finally` has long since restored it. A registration-time swap is
 * therefore a no-op for the broker, and looks like it works.
 *
 * `auth.passwords.tenant` itself is defaulted by
 * {@see \Nvade\Numerosis\Support\HostConfig::tenantPasswordBroker()}; the
 * tokens live in the tenant database's own `password_reset_tokens` table,
 * which `database/migrations/tenant/0001_01_01_000000_create_users_table.php`
 * creates.
 *
 * Central requests never initialize tenancy, so they keep whatever
 * `fortify.passwords` the host configured; revert restores the captured
 * value rather than a config default, so nesting and queued work behave.
 *
 * **Registered as a container singleton** in
 * `NumerosisServiceProvider::packageRegistered()`, and it does not work
 * otherwise: `Tenancy::getBootstrappers()` is `array_map('app',
 * config('tenancy.bootstrappers'))`, resolved afresh every time tenancy
 * starts *and* every time it ends, so an unbound class hands `revert()` a
 * different instance than `bootstrap()` wrote to and the captured value is
 * gone. `AuthGuardBootstrapper` hides the same trap behind a fallback
 * (`?? Context::Central->guard()`) rather than escaping it.
 */
class PasswordBrokerBootstrapper implements TenancyBootstrapper
{
    private ?string $brokerBeforeTenancy = null;

    private bool $bootstrapped = false;

    public function __construct(protected Repository $config) {}

    public function bootstrap(Tenant $tenant): void
    {
        $broker = $this->config->get('fortify.passwords');

        $this->brokerBeforeTenancy = is_string($broker) ? $broker : null;
        $this->bootstrapped = true;

        $this->config->set('fortify.passwords', $this->tenantBroker());
    }

    public function revert(): void
    {
        if (! $this->bootstrapped) {
            return;
        }

        $this->config->set('fortify.passwords', $this->brokerBeforeTenancy);

        $this->brokerBeforeTenancy = null;
        $this->bootstrapped = false;
    }

    private function tenantBroker(): string
    {
        $broker = $this->config->get('numerosis.auth.password_brokers.tenant');

        return is_string($broker) && $broker !== '' ? $broker : 'tenant';
    }
}
