<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Tls;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Queries\GetServableDomains;
use Nvade\Numerosis\Http\Controllers\Controller;

/**
 * Traefik dynamic configuration for its HTTP provider: one router per verified
 * hostname, each asking the configured certificate resolver for a certificate.
 *
 * Reads the same verified-domain query the Caddy ask endpoint does, so a domain
 * cannot be servable to one proxy and not the other.
 */
class RoutersController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $service = Config::string('numerosis.tenancy.custom_domains.tls.service', 'numerosis');
        $resolver = Config::string('numerosis.tenancy.custom_domains.tls.cert_resolver', 'letsencrypt');

        $routers = [];

        foreach (GetServableDomains::run() as $index => $domain) {
            $routers['numerosis-tenant-'.$index] = [
                'rule' => "Host(`{$domain}`)",
                'service' => $service,
                'entryPoints' => ['websecure'],
                'tls' => ['certResolver' => $resolver],
            ];
        }

        return response()->json(['http' => ['routers' => $routers]]);
    }
}
