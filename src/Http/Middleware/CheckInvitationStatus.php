<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Nvade\Numerosis\Contracts\Invitations\InvitationRepository;
use Symfony\Component\HttpFoundation\Response;

class CheckInvitationStatus
{
    public function __construct(private readonly Redirector $redirector, private readonly UrlGenerator $urlGenerator) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');

        if (! $token) {
            return $next($request);
        }

        $invitation = resolve(InvitationRepository::class)->findOrFailByToken($token);

        if ($invitation->isAccepted()) {
            return $this->refuse(__('This invitation has already been accepted.'));
        }

        if ($invitation->isExpired()) {
            return $this->refuse(__('This invitation has expired.'));
        }

        return $next($request);
    }

    /**
     * Flash the reason and bounce to the root of the current host.
     *
     * `resources/views/partials/toasts.blade.php` renders the flashed key, so
     * the reason still reaches the visitor on any page that includes it.
     */
    private function refuse(string $message): Response
    {
        session()->flash('error', $message);

        return $this->redirector->to($this->urlGenerator->to('/'));
    }
}
