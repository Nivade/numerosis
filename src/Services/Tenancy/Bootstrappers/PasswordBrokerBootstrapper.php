<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy\Bootstrappers;

use Illuminate\Contracts\Config\Repository;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Points `fortify.passwords` at the tenant password broker for the duration
 * of tenancy, the way {@see AuthGuardBootstrapper} does for the default
 * guard. Without it a tenant subdomain's forgot/reset request resolves the
 * central `users` provider (`CentralUser`): "we can't find a user with that
 * email" for a tenant-only account, or a reset link mailed for a
 * same-addressed central account, nothing logged either way.
 *
 * The swap cannot live where the guard's does. `Numerosis::routes()` swaps
 * `fortify.guard` around its `require` of Fortify's route file because
 * `routes/routes.php` bakes `'guest:'.config('fortify.guard')` into route
 * middleware at registration time. Nothing bakes the broker:
 * `PasswordResetLinkController::broker()`, `NewPasswordController::broker()`
 * and `PasswordController::broker()` read `fortify.passwords` when the
 * request arrives, by which time a `finally` has restored it. A broker also
 * reads its user provider from `auth.passwords.*`, a key `auth.guards.*` has
 * no bearing on.
 *
 * `auth.passwords.tenant` is defaulted by
 * {@see \Nvade\Numerosis\Support\HostConfig::tenantPasswordBroker()}; its
 * tokens live in the tenant database's own `password_reset_tokens` table,
 * which `database/migrations/tenant/0001_01_01_000000_create_users_table.php`
 * creates.
 *
 * Bind this as a container singleton or it silently does nothing:
 * `Tenancy::getBootstrappers()` is `array_map('app',
 * config('tenancy.bootstrappers'))`, resolved afresh every time tenancy
 * starts and every time it ends, so an unbound class hands `revert()` a
 * different instance than `bootstrap()` wrote to and the captured value is
 * gone. `NumerosisServiceProvider::packageRegistered()` binds it.
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
