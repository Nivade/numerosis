<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Tenancy;

enum ImpersonationEndReason: string
{
    case Exit = 'exit';
    case Expired = 'expired';
}
