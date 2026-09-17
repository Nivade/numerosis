<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Audit;

/**
 * Who an activity entry is attributable to, stored as its `actor` property.
 * A queued provisioning step has no authenticated user, and a row with no
 * causer at all reads as anonymous instead of as the system.
 */
enum ActivityActor: string
{
    case User = 'user';
    case Staff = 'staff';
    case System = 'system';
}
