<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Api;

use Nvade\Numerosis\Models\Central\Tenant;
use Spatie\LaravelData\Data;

/**
 * The tenant as the API describes it. Declared field by field instead of from
 * the model, so a column added later does not appear in a customer's payload
 * without anyone deciding it should.
 */
class TenantData extends Data
{
    public function __construct(
        public string $id,
        public ?string $name,
        public bool $suspended,
        public bool $closed,
        public ?string $created_at,
    ) {}

    public static function fromTenant(Tenant $tenant): self
    {
        return new self(
            id: (string) $tenant->getTenantKey(),
            name: $tenant->name,
            suspended: $tenant->suspended_at !== null,
            closed: $tenant->closed_at !== null,
            created_at: $tenant->created_at?->toIso8601String(),
        );
    }
}
