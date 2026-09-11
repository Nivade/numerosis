<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Responses\Billing;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;

/**
 * Wraps a redirect so a checkout route can answer with one without going
 * through Stripe's own Responsable Checkout object.
 */
class RedirectResponsable implements Responsable
{
    /**
     * @param  array<string, mixed>  $flash
     */
    public function __construct(
        private readonly string $url,
        private readonly array $flash = [],
    ) {}

    public function toResponse($request): RedirectResponse
    {
        return Redirect::to($this->url)->with($this->flash);
    }
}
