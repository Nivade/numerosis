<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Settings;

use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Nvade\Numerosis\Actions\Auth\ResendVerificationNotification;
use Nvade\Numerosis\Actions\Auth\UpdateUserProfile;
use Nvade\Numerosis\Concerns\Auth\RequiresAuthenticatedUser;
use Nvade\Numerosis\Routing\RouteNames;

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
        // Empty for an OAuth account whose provider returned no address. The
        // field is `required` on submit, so saving one is how they gain it.
        $this->email = $user->email ?? '';
    }

    public function updateProfileInformation(): void
    {
        $user = $this->authenticatedUser();

        UpdateUserProfile::run($user, [
            'name' => $this->name,
            'email' => $this->email,
        ]);

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
