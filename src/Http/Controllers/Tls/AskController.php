<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Tls;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nvade\Numerosis\Actions\Queries\GetServableDomains;
use Nvade\Numerosis\Http\Controllers\Controller;

/**
 * Caddy's on-demand TLS ask endpoint. A 200 tells Caddy to issue a certificate
 * for the hostname, and any other status tells it to refuse.
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
