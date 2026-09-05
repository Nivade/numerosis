<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Exceptions;

/**
 * Extra keys attached to every reported exception. Bind your own to add
 * domain context, or depend on the default in a constructor to decorate it.
 */
interface ProvidesExceptionContext
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array;
}
