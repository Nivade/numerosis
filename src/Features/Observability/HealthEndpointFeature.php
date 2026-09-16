<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Observability;

use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Features\Concerns\IsNamedFeature;

/**
 * The unauthenticated health document at `numerosis.routes.health_path`, off
 * by default. It answers counts and booleans and no tenant identity, but
 * whether an installation publishes queue depth at all is the host's call.
 */
class HealthEndpointFeature implements NamedFeature
{
    use IsNamedFeature;

    public const NAME = 'health_endpoint';
}
