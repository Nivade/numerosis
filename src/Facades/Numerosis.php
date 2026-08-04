<?php

namespace Nvade\Numerosis\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Nvade\Numerosis\Numerosis
 */
class Numerosis extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Nvade\Numerosis\Numerosis::class;
    }
}
