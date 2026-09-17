<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Auth;

enum DataExportStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
