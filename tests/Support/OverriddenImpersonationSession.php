<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use Illuminate\Database\Eloquent\Attributes\Table;
use Nvade\Numerosis\Models\Central\ImpersonationSession;

/**
 * An ImpersonationSession subclass outside `App\Models\`, which is the only
 * shape that tells `numerosis.models.*` apart from the conventional-path
 * guess.
 */
#[Table(name: 'impersonation_sessions')]
class OverriddenImpersonationSession extends ImpersonationSession {}
