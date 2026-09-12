<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy;

use Illuminate\Contracts\Config\Repository;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Points `fortify.passwords` at the tenant broker while tenancy is initialized.
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
