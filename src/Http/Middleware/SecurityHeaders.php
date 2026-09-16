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

        if (! Config::boolean('numerosis.security.headers.enabled', true) || $this->isExcepted($request)) {
            return $response;
        }

        foreach ($this->simpleHeaders() as $header => $key) {
            $value = Config::get('numerosis.security.headers.'.$key);

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
    protected function simpleHeaders(): array
    {
        return [
            'Strict-Transport-Security' => 'strict_transport_security',
            'X-Content-Type-Options' => 'x_content_type_options',
            'Referrer-Policy' => 'referrer_policy',
            'X-Frame-Options' => 'x_frame_options',
            'Permissions-Policy' => 'permissions_policy',
        ];
    }

    protected function applyContentSecurityPolicy(Response $response): void
    {
        if (! Config::boolean('numerosis.security.headers.content_security_policy.enabled', false)) {
            return;
        }

        $policy = $this->policy();

        if ($policy === '') {
            return;
        }

        $header = Config::boolean('numerosis.security.headers.content_security_policy.report_only', true)
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($header, $policy);
    }

    protected function policy(): string
    {
        $directives = Config::array('numerosis.security.headers.content_security_policy.directives', []);

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

        $reportUri = Config::get('numerosis.security.headers.content_security_policy.report_uri');

        if (is_string($reportUri) && $reportUri !== '') {
            $compiled[] = 'report-uri '.$reportUri;
        }

        return implode('; ', $compiled);
    }

    protected function isExcepted(Request $request): bool
    {
        $patterns = array_filter(
            Config::array('numerosis.security.headers.except', []),
            is_string(...)
        );

        return $patterns !== [] && $request->is(...array_values($patterns));
    }

    protected function isHtml(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type');

        return $contentType === null || str_contains($contentType, 'text/html');
    }
}
