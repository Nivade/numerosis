<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Settings;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session as SessionFacade;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Actions\Auth\RevokeOtherSessions;
use Nvade\Numerosis\Concerns\Auth\RequiresAuthenticatedUser;
use Nvade\Numerosis\Contracts\Auth\SessionRegistry;
use Nvade\Numerosis\Data\Auth\DeviceSession;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

/**
 * Lists where this account is signed in and revokes any of it. Sessions are
 * read through {@see SessionRegistry}, which only the `database` driver can
 * enumerate; the revoke-everywhere path works on every driver, because it goes
 * through the password stamp rather than through stored rows.
 */
#[Layout('numerosis-layouts::app')]
class Sessions extends Component
{
    use RequiresAuthenticatedUser;

    public string $password = '';

    public function revoke(string $sessionId): void
    {
        $user = $this->authenticatedUser();

        resolve(SessionRegistry::class)->forget(Context::Central->guard(), $user->id, $sessionId);
    }

    public function revokeOthers(): void
    {
        $this->validate(['password' => ['required', 'string']]);

        $user = $this->authenticatedUser();
        $guard = Auth::guard(Context::Central->guard());

        try {
            if ($guard instanceof StatefulGuard) {
                $guard->logoutOtherDevices($this->password);
            }
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'password' => __('This password does not match our records.'),
            ]);
        } finally {
            $this->reset('password');
        }

        RevokeOtherSessions::run(Context::Central->guard(), $user->id);

        $this->dispatch('sessions-revoked');
    }

    public function deviceLabel(DeviceSession $session): string
    {
        return $session->deviceLabel();
    }

    public function render(): View
    {
        $registry = resolve(SessionRegistry::class);
        $sessions = $registry->forUser(Context::Central->guard(), $this->authenticatedUser()->id);

        return view('numerosis::livewire.settings.sessions', [
            'sessions' => $sessions,
            'listable' => $registry->listable(),
            'currentSessionId' => SessionFacade::getId(),
            'tenantNames' => $this->tenantNames($sessions),
        ]);
    }

    /**
     * @param  list<DeviceSession>  $sessions
     * @return array<string, string>
     */
    private function tenantNames(array $sessions): array
    {
        $ids = array_values(array_filter(
            array_map(fn (DeviceSession $session): ?string => $session->tenantId, $sessions),
            fn (?string $id): bool => $id !== null,
        ));

        if ($ids === []) {
            return [];
        }

        /** @var class-string<Tenant> $model */
        $model = Numerosis::model(Tenant::class);

        /** @var array<string, string> $names */
        $names = $model::query()->whereKey($ids)->pluck('name', 'id')->all();

        return $names;
    }
}
