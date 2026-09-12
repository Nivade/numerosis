<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums;

/**
 * Backed, not pure — Livewire only supports backed enums as public
 * properties.
 */
enum FetchState: string
{
    case NotAttempted = 'not_attempted';
    case Loaded = 'loaded';
    case Failed = 'failed';
}
