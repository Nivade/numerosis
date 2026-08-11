<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns;

/**
 * Identifies a model by `global_id`, the column that recognises the same
 * person across the central and tenant databases.
 *
 * Compose it into any model that syncs between the two.
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
