<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums;

/**
 * Backed instead of pure, since Livewire only supports backed enums as
 * public properties.
 */
enum FetchState: string
{
    case NotAttempted = 'not_attempted';
    case Loaded = 'loaded';
    case Failed = 'failed';
}
