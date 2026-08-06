<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns;

/**
 * `Stancl\Tenancy\Contracts\Syncable::getGlobalIdentifierKeyName()`/
 * `getGlobalIdentifierKey()` are how stancl's sync listener finds "the same
 * person" across the central and tenant databases. `CentralUser` and
 * `Tenant\User` both answer `global_id`/`$this->global_id` — not by
 * coincidence, but because `global_id` *is* the package's cross-database
 * identity column (see .claude/rules/tenant-caching.md's `global_id`
 * bullets); a model syncable under this package always uses it. One of the
 * six traits redone from R8's Phase 5 debt
 * (.claude/plans/package-extraction.md, Phase 6.6) — the other five were
 * judged obsolete rather than redone: they existed to support the abstract
 * model design D8 reversed, and nothing in the current concrete-model
 * shape needs a marker trait in their place.
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
