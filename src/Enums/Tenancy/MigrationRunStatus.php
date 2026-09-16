<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Tenancy;

/**
 * What one tenant's leg of a fleet migration run did.
 *
 * `Skipped` is a tenant the run deliberately passed over — already at the
 * target, or resumed past — and is distinct from `Succeeded` so a resumed run
 * does not read as having migrated the whole fleet again.
 */
enum MigrationRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
