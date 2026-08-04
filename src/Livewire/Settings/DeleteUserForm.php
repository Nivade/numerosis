<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Settings;

use Nvade\Numerosis\Actions\Auth\DeleteUserAccount;
use Nvade\Numerosis\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class DeleteUserForm extends Component
{
    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        $user = Auth::user();

        if (DeleteUserAccount::run($user)) {
            $logout();

            $this->redirect('/', navigate: true);
        } else {
            $this->addError('password', 'You cannot delete your account while you are the owner of one or more tenants. Please transfer ownership or delete the tenants first.');
        }
    }
}
