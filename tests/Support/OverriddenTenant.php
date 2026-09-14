<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use Nvade\Numerosis\Models\Central\Tenant;

/**
 * A tenant subclass outside `App\Models\`, which is the only shape that tells
 * `numerosis.models.*` apart from the conventional-path guess.
 */
class OverriddenTenant extends Tenant {}
