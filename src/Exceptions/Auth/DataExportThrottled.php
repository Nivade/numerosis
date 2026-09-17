<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Exceptions\Auth;

use Nvade\Numerosis\Exceptions\DomainException;

class DataExportThrottled extends DomainException
{
    public static function until(int $hours): self
    {
        return new self("You already asked for your data in the last {$hours} hours. The link from that request is still the current one.");
    }
}
