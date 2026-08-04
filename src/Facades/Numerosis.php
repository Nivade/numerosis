<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Facades;

use Nvade\Numerosis\Support\Numerosis as NumerosisManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void addTenantColumns(list<string> $columns)
 * @method static list<string> tenantColumns()
 *
 * @see NumerosisManager
 */
class Numerosis extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return NumerosisManager::class;
    }
}
