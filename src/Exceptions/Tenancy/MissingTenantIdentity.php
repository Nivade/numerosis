<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Exceptions\Tenancy;

use Nvade\Numerosis\Exceptions\DomainException;

/**
 * The wizard reached a step needing the tenant's name or slug before the step
 * collecting it was submitted.
 *
 * It carries the wizard step to send the user back to, because a validation
 * error on the step they are looking at would be invisible — the field is on
 * a screen they have not filled in.
 */
class MissingTenantIdentity extends DomainException
{
    public function __construct(public readonly string $step)
    {
        parent::__construct("The registration wizard has no tenant identity yet; [{$step}] was not completed.");
    }
}
