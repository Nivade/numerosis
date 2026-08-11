<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Tenancy;

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Contracts\NamedFeature;
use Stancl\Tenancy\Features\UserImpersonation;

/**
 * Lets a central admin open a session as a tenant's owner, for support.
 *
 * Registers stancl's own `UserImpersonation` feature into
 * `config('tenancy.features')` — the `tenancy()->impersonate()` macro and
 * `impersonate/{token}` route both come from that, not from anything in
 * this class. Remove this from `numerosis.features` to drop the "Impersonate
 * owner" action, the route, and the underlying macro all at once.
 */
class ImpersonationFeature implements NamedFeature
{
    public const NAME = 'impersonation';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        /** @var list<class-string> $features */
        $features = Config::array('tenancy.features');

        if (! in_array(UserImpersonation::class, $features, true)) {
            $features[] = UserImpersonation::class;
            Config::set('tenancy.features', $features);
        }
    }
}
