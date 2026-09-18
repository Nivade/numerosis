<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the response headers from `numerosis.security.headers`. Machine-to-
 * machine routes sharing the `web` group are exempt through
 * `numerosis.security.headers.except`, since Stripe's webhook answers with a
 * `text/html` response a content-type check would not tell apart from a page.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (! $response instanceof Response || ! $this->isHtml($response)) {
            return $response;
        }

        if (! Config::boolean($this->key('enabled'), true) || $this->isExcepted($request)) {
            return $response;
        }

        foreach ($this->simpleHeaders() as $header => $key) {
            $value = Config::get($this->key($key));

            if (is_string($value) && $value !== '') {
                $response->headers->set($header, $value);
            }
        }

        $this->applyContentSecurityPolicy($response);

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function simpleHeaders(): array
    {
        return [
            'Strict-Transport-Security' => 'strict_transport_security',
            'X-Content-Type-Options' => 'x_content_type_options',
            'Referrer-Policy' => 'referrer_policy',
            'X-Frame-Options' => 'x_frame_options',
            'Permissions-Policy' => 'permissions_policy',
        ];
    }

    private function applyContentSecurityPolicy(Response $response): void
    {
        if (! Config::boolean($this->key('content_security_policy.enabled'), false)) {
            return;
        }

        $policy = $this->policy();

        if ($policy === '') {
            return;
        }

        $header = Config::boolean($this->key('content_security_policy.report_only'), true)
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($header, $policy);
    }

    private function policy(): string
    {
        $directives = Config::array($this->key('content_security_policy.directives'), []);

        $compiled = [];

        foreach ($directives as $directive => $sources) {
            if (! is_string($directive)) {
                continue;
            }

            $sources = is_array($sources) ? implode(' ', array_filter($sources, is_string(...))) : $sources;

            $compiled[] = is_string($sources) && $sources !== ''
                ? $directive.' '.$sources
                : $directive;
        }

        $reportUri = Config::get($this->key('content_security_policy.report_uri'));

        if (is_string($reportUri) && $reportUri !== '') {
            $compiled[] = 'report-uri '.$reportUri;
        }

        return implode('; ', $compiled);
    }

    private function isExcepted(Request $request): bool
    {
        $patterns = array_filter(
            Config::array($this->key('except'), []),
            is_string(...)
        );

        return $patterns !== [] && $request->is(...array_values($patterns));
    }

    private function isHtml(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type');

        return $contentType === null || str_contains($contentType, 'text/html');
    }

    private function key(string $suffix): string
    {
        return 'numerosis.security.headers.'.$suffix;
    }
}
