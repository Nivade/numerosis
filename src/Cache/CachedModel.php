<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Cache;

use Illuminate\Database\Eloquent\Model;

/**
 * The counterpart to caching attributes instead of models: rebuilds one from
 * the row that was stored, with no relations loaded and `exists` set, exactly
 * as a fresh query would.
 */
final class CachedModel
{
    private function __construct() {}

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public static function hydrate(string $class, array $attributes): Model
    {
        return (new $class)->newFromBuilder($attributes);
    }
}
