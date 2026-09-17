<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Tls;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nvade\Numerosis\Actions\Queries\GetServableDomains;
use Nvade\Numerosis\Http\Controllers\Controller;

/**
 * Caddy's on-demand TLS ask endpoint: 200 means "issue a certificate for this
 * hostname", anything else means do not.
 *
 * Unauthenticated by necessity — Caddy calls it before any certificate exists —
 * so it answers with a status and an empty body, and leaks nothing beyond
 * whether the hostname is ours. The answer is cached per hostname, because Caddy
 * asks once per new SNI and an uncached query here is a denial-of-service
 * vector.
 */
class AskController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $domain = $request->query('domain');

        if (! is_string($domain) || trim($domain) === '') {
            return response()->noContent(Response::HTTP_NOT_FOUND);
        }

        return response()->noContent(GetServableDomains::includes($domain)
            ? Response::HTTP_OK
            : Response::HTTP_NOT_FOUND);
    }
}
