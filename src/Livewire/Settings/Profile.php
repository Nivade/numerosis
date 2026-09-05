<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Settings;

use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Actions\Auth\ResendVerificationNotification;
use Nvade\Numerosis\Actions\Auth\UpdateUserProfile;
use Nvade\Numerosis\Concerns\RequiresAuthenticatedUser;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Support\Routes\RouteNames;

#[Layout('numerosis-layouts::app')]
class Profile extends Component
{
    use RequiresAuthenticatedUser;

    public string $name = '';

    public string $email = '';

    public function mount(): void
    {
        $user = $this->authenticatedUser();

        $this->name = $user->name;
        $this->email = $user->email;
    }

    public function updateProfileInformation(): void
    {
        $user = $this->authenticatedUser();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(CentralUser::class)->ignore($user->id),
            ],
        ]);

        UpdateUserProfile::run($user, $validated);

        $this->dispatch('profile-updated', name: $user->name);
    }

    public function resendVerificationNotification(): void
    {
        $user = $this->authenticatedUser();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route(RouteNames::tenantsMine(), absolute: false));

            return;
        }

        ResendVerificationNotification::run($user);

        Session::flash('status', 'verification-link-sent');
    }

    public function render(): View
    {
        return view('numerosis::livewire.settings.profile');
    }
}
