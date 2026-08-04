<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Exceptions;

use RuntimeException;

/**
 * Base for expected, user-facing failures — invalid state reached through
 * normal use (an already-accepted invitation, a claimed domain), not a bug.
 * Distinct from RuntimeException/LogicException throws elsewhere in the app,
 * which signal a programmer error and must stay uncaught by the UI.
 */
abstract class DomainException extends RuntimeException implements ShowsMessageToUser {}
