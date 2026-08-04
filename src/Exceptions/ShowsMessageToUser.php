<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Exceptions;

use Throwable;

/**
 * Marks an exception whose message is safe to display to the end user
 * verbatim, as opposed to an internal error message that happens to be
 * caught and shown by accident.
 */
interface ShowsMessageToUser extends Throwable {}
