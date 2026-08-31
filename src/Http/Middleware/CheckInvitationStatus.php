<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Filament\Notifications\Notification;
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
     * The invitation routes are core's and answer with or without
     * nvade/numerosis-filament installed, so the Filament notification is
     * guarded: `use Filament\Notifications\Notification` is lazy, but
     * reaching `Notification::make()` autoloads it, and this line is reached
     * on an ordinary expired-invitation link rather than from a panel.
     * Without Filament the redirect still happens; only the toast is lost.
     */
    private function refuse(string $message): Response
    {
        if (class_exists(Notification::class)) {
            Notification::make()->danger()->title($message)->send();
        } else {
            session()->flash('error', $message);
        }

        return $this->redirector->to($this->urlGenerator->to('/'));
    }
}
