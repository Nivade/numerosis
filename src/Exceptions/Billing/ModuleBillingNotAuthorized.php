<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Exceptions\Billing;

use Nvade\Numerosis\Exceptions\DomainException;

/**
 * Thrown when a tenant user tries to start or stop paying for a module without
 * being the tenant owner or holding the matching `purchase modules` /
 * `cancel modules` permission — see Nvade\Numerosis\Policies\ModulePolicy.
 *
 * A user-facing refusal rather than a 403: the actor is a legitimate member of
 * the tenant who simply may not spend its money, and both call sites already
 * render DomainException messages as notifications.
 */
class ModuleBillingNotAuthorized extends DomainException {}
