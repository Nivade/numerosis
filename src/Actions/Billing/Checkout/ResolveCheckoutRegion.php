<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;
use Torann\GeoIP\Facades\GeoIP;

/**
 * ISO country code for a checkout request's IP, used to order Stripe's
 * Payment Element and pre-fill the Address Element — never to restrict
 * eligibility, which stays Stripe's call.
 *
 * Returns null on anything short of a confident hit: private/local IP
 * (every dev environment), a database miss, or the driver itself throwing —
 * torann/geoip's own `getLocation()` already swallows a lookup miss and
 * returns `default_location` (`'default' => true`), but a misconfigured or
 * missing MaxMind database file throws instead of falling back, so this
 * still needs its own guard. Callers must treat null as "use the default
 * order", never as an error — this runs on every checkout page load, not a
 * path that should ever fail the request.
 *
 * @method static ?string run(Request $request)
 */
class ResolveCheckoutRegion
{
    use AsAction;

    public function handle(Request $request): ?string
    {
        $ip = $request->ip();

        if ($ip === null) {
            return null;
        }

        try {
            $location = GeoIP::getLocation($ip);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if (($location->default ?? false) === true) {
            return null;
        }

        $isoCode = $location->iso_code ?? null;

        return is_string($isoCode) && $isoCode !== '' ? $isoCode : null;
    }
}
