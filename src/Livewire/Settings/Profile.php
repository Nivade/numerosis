<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Settings;

use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;
use Nvade\Numerosis\Actions\Auth\ResendVerificationNotification;
use Nvade\Numerosis\Actions\Auth\UpdateUserProfile;
use Nvade\Numerosis\Concerns\RequiresAuthenticatedUser;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Support\Routes\RouteNames;

class Profile extends Component
{
    use RequiresAuthenticatedUser;

    public string $name = '';

    public string $email = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $user = $this->authenticatedUser();

        $this->name = $user->name;
        $this->email = $user->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
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

    /**
     * Send an email verification notification to the current user.
     */
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

    /**
     * Livewire's default view guess rebuilds the view name from this
     * class's own namespace segments, resolved against the host's
     * `resources/views/livewire/*` — wrong once the class ships from the
     * package. See `.claude/plans/package-extraction.md` step 2.
     */
    public function render(): View
    {
        return view('numerosis::livewire.settings.profile');
    }
}
