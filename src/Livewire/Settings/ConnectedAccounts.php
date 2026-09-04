<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Settings;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Component;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\SocialAccount;

/**
 * Lists the user's connected identities. Unlinking is a plain `<form>`
 * posting the DELETE route from the view, not a Livewire method — every
 * public method on a component is client-invokable regardless of prior
 * state, and `throttle:` middleware never covers `/livewire/update`
 * (`.ai/rules/auth-login.md`, "Two lessons").
 */
class ConnectedAccounts extends Component
{
    public function render(): View
    {
        $user = GetAuthenticatedUser::run();

        /** @var Collection<int, SocialAccount> $accounts */
        $accounts = $user instanceof CentralUser ? $user->socialAccounts()->get() : new Collection;

        return view('numerosis::livewire.settings.connected-accounts', [
            'accounts' => $accounts,
        ]);
    }
}
