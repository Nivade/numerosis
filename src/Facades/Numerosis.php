<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Post-boot access to {@see \Nvade\Numerosis\Support\Numerosis}, for `swap()`
 * and `spy()` in tests.
 *
 * `routes()`, `middleware()`, `exceptions()` and `configure()` must not be
 * called through here: they run while `ApplicationBuilder` is being built,
 * where `Facade::getFacadeRoot()` is null and every call throws
 * `RuntimeException: A facade root has not been set`. `bootstrap/app.php`
 * imports `Support\Numerosis` directly.
 *
 * @see \Nvade\Numerosis\Support\Numerosis
 */
class Numerosis extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Nvade\Numerosis\Support\Numerosis::class;
    }
}
