<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Api;

use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Features\Concerns\IsNamedFeature;

/**
 * The read-only `/api/v1` surface and its token screen. Off by default,
 * since a token with nothing to call is not a feature.
 */
class ReadApiFeature implements NamedFeature
{
    use IsNamedFeature;

    public const NAME = 'read_api';
}
