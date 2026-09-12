<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Exceptions\Tenancy;

use Nvade\Numerosis\Exceptions\DomainException;

/**
 * The wizard reached a step that needs the tenant's name or slug before the
 * step collecting it was submitted. Callers send the user back to that step
 * rather than surfacing this.
 */
class MissingTenantIdentity extends DomainException {}
