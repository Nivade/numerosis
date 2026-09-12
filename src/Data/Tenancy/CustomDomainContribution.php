<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Tenancy;

use Nvade\Numerosis\Contracts\Tenancy\PersistsToProvisionColumns;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Override;
use Spatie\LaravelData\Data;

/**
 * The tenant's own fully-qualified domain, as opposed to the slug. Only
 * collected under {@see \Nvade\Numerosis\Enums\Tenancy\IdentificationMode::CustomDomain},
 * so its absence is the ordinary case rather than an error.
 */
final class CustomDomainContribution extends Data implements PersistsToProvisionColumns
{
    public function __construct(public string $custom_domain) {}

    #[Override]
    public static function fromProvision(TenantProvision $provision): ?static
    {
        return $provision->custom_domain === null
            ? null
            : new self($provision->custom_domain);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toProvisionColumns(): array
    {
        return ['custom_domain' => $this->custom_domain];
    }
}
