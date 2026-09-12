<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Tenancy;

use Nvade\Numerosis\Contracts\Tenancy\PersistsToProvisionColumns;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Override;
use Spatie\LaravelData\Data;

/**
 * Who will own the tenant. Absent when nobody will — a system or demo tenant,
 * or one imported from elsewhere — which is what makes `AddTenantOwner`
 * skippable rather than the pipeline's only unconditional relationship.
 *
 * A column rather than JSON: checkout looks a reservation up by its owner, and
 * refuses one claimed by somebody else.
 */
final class OwnerContribution extends Data implements PersistsToProvisionColumns
{
    public function __construct(public string $global_id) {}

    #[Override]
    public static function fromProvision(TenantProvision $provision): ?static
    {
        $globalId = $provision->global_id;

        return $globalId === null || $globalId === ''
            ? null
            : new self($globalId);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toProvisionColumns(): array
    {
        return ['global_id' => $this->global_id];
    }
}
