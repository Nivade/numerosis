<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Api;

use Nvade\Numerosis\Models\Central\Domain;
use Spatie\LaravelData\Data;

/**
 * A servable custom domain. The verification token is omitted; it proves
 * ownership only, and no integration needs to read it.
 */
class DomainData extends Data
{
    public function __construct(
        public string $domain,
        public string $status,
        public ?string $created_at,
    ) {}

    public static function fromDomain(Domain $domain): self
    {
        return new self(
            domain: $domain->domain,
            status: $domain->status->value,
            created_at: $domain->created_at?->toIso8601String(),
        );
    }
}
