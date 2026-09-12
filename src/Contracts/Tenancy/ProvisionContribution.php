<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

/**
 * A piece of provisioning data beyond a tenant's identity, contributed by
 * whoever collects it and read by whichever steps declare they consume it.
 *
 * `TenantProvisionData` carries only the slug, name and owner, because those
 * are what every shipped step dereferences and what keys the provision row.
 * Everything else arrives through here, including the package's own billing
 * and custom-domain data — core uses the same seam a host does, so there is no
 * privileged tier of fields.
 *
 * Implement on a `Spatie\LaravelData\Data` subclass. Contributions are stored
 * on the provision row keyed by class name and rehydrated with `Data::from()`,
 * so they stay typed end to end. Implement
 * {@see PersistsToProvisionColumns} as well when a field has to be queryable.
 */
interface ProvisionContribution
{
    /**
     * Both of these are `Spatie\LaravelData\Data`'s own signatures, declared
     * here so the round trip through the provision row is part of the
     * contract rather than an assumption the storage code has to cast around.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    public static function from(mixed ...$payloads): static;
}
