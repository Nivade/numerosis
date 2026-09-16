<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Admin;

use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Features\Concerns\IsNamedFeature;

/**
 * The staff screens under `numerosis.routes.staff_prefix`, off by default. A
 * host adopting this package into an existing app usually has an admin area
 * of its own, so nothing here registers until the class is listed in
 * `numerosis.features`.
 */
class StaffPanelFeature implements NamedFeature
{
    use IsNamedFeature;

    public const NAME = 'staff_panel';
}
