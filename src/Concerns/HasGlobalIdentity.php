<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns;

/**
 * Identifies a model by `global_id`, the column that recognises the same
 * person across the central and tenant databases.
 *
 * Compose it into any model that syncs between the two.
 *
 * `stancl/tenancy` v3's `ResourceSyncing` trait calls both methods without
 * declaring them, which is why they live here. **On dev-master that trait
 * declares them itself**, so a model composing both would hit a fatal
 * trait-method collision — see `.ai/rules/stancl-tenancy-v4.md` when
 * porting.
 */
trait HasGlobalIdentity
{
    public function getGlobalIdentifierKeyName(): string
    {
        return 'global_id';
    }

    public function getGlobalIdentifierKey(): string
    {
        return $this->global_id;
    }
}
