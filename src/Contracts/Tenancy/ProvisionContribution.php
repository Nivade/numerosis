<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

/**
 * A piece of provisioning data beyond a tenant's identity, implemented on a
 * `Spatie\LaravelData\Data` subclass and rehydrated from the provision row
 * with `Data::from()`. Implement {@see PersistsToProvisionColumns} as well
 * when a field has to be queryable.
 */
interface ProvisionContribution
{
    /**
     * Both of these are `Spatie\LaravelData\Data`'s own signatures, declared
     * here so the round trip through the provision row is part of the
     * contract, and not an assumption the storage code has to cast around.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    public static function from(mixed ...$payloads): static;
}
