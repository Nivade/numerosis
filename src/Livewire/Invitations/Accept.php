<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Invitations;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Actions\Auth\LoginUser;
use Nvade\Numerosis\Actions\Invitations\AcceptInvitation;
use Nvade\Numerosis\Contracts\Invitations\CreatesInvitedUser;
use Nvade\Numerosis\Contracts\Invitations\InvitationRepository;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Features\Turnstile\TurnstileFeature;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Tenant\Invitation;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Support\Numerosis;

#[Layout('layouts::auth')]
class Accept extends Component
{
    public Invitation $invitation;

    public bool $existingUser = false;

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?string $turnstileResponse = null;

    public function mount(string $token): void
    {
        $this->invitation = resolve(InvitationRepository::class)->findOrFailByToken($token);

        $centralUserClass = Numerosis::model(CentralUser::class);

        $this->existingUser = $centralUserClass::where('email', $this->invitation->email)->exists();
    }

    public function accept(LoginUser $loginUser): void
    {
        $this->validate([
            'turnstileResponse' => TurnstileFeature::rules(),
        ]);

        $centralUserClass = Numerosis::model(CentralUser::class);

        /** @var CentralUser|null $centralUser */
        $centralUser = $centralUserClass::where('email', $this->invitation->email)->first();

        if ($centralUser === null) {
            $this->validate([
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);

            $centralUser = resolve(CreatesInvitedUser::class)->create($this->invitation, $this->name, $this->password);
        }

        try {
            AcceptInvitation::run($this->invitation, $centralUser);
        } catch (ShowsMessageToUser $e) {
            Session::flash('error', $e->getMessage());

            return;
        }

        $tenantUserClass = Numerosis::model(TenantUser::class);

        /** @var TenantUser $user */
        $user = $tenantUserClass::where('global_id', $centralUser->global_id)->firstOrFail();

        LoginUser::run($user, remember: false);

        $this->redirect('/', navigate: true);
    }

    public function render(): View
    {
        return view('numerosis::livewire.invitations.accept');
    }
}
