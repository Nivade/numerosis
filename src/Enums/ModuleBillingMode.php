<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums;

enum ModuleBillingMode: string
{
    case Recurring = 'recurring';
    case OneTime = 'one_time';
}
