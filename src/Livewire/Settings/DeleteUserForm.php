<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Settings;

use Illuminate\View\View;
use Livewire\Component;
use Nvade\Numerosis\Actions\Auth\DeleteUserAccount;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Livewire\Actions\Logout;
use Nvade\Numerosis\Models\Central\CentralUser;

class DeleteUserForm extends Component
{
    public string $password = '';

    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        $user = GetAuthenticatedUser::run();

        // A tenant user, or none at all once the session expired mid-form,
        // must not reach the delete action: it would target the wrong table
        // or fatal on null.
        if (! $user instanceof CentralUser) {
            $this->addError('password', __('numerosis::auth.failed'));

            return;
        }

        if (DeleteUserAccount::run($user)) {
            $logout();

            $this->redirect('/', navigate: true);
        } else {
            $this->addError('password', 'You cannot delete your account while you are the owner of one or more tenants. Please transfer ownership or delete the tenants first.');
        }
    }

    public function render(): View
    {
        return view('numerosis::livewire.settings.delete-user-form');
    }
}
