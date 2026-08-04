<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Nvade\Numerosis\Models\Tenant\Invitation;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
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

        $invitation = Invitation::where('token', $token)->firstOrFail();

        if ($invitation->isAccepted()) {
            Notification::make()
                ->danger()
                ->title(__('This invitation has already been accepted.'))
                ->send();

            return $this->redirector->to($this->urlGenerator->to('/'));
        }

        if ($invitation->isExpired()) {
            Notification::make()
                ->danger()
                ->title(__('This invitation has expired.'))
                ->send();

            return $this->redirector->to($this->urlGenerator->to('/'));
        }

        return $next($request);
    }
}
