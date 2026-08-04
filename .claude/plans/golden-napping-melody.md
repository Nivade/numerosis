# Clean up the social login button architecture

**Status: ✅ Executed.** `grid.blade.php` now derives buttons from
`config('auth.social.providers')` filtered against `config('services')` —
no more hardcoded 5-provider list, no more buttons for unconfigured
providers.

## Context

The previous plan (invitation acceptance) is done and merged; this is a
separate follow-up the user flagged directly: "the social button login...
isn't clean." Investigating the button rendering path (not the
redirect/callback controllers, which were already fixed) turns up a real bug
behind the mess, not just style:

**3 of the 5 rendered social-login buttons are broken today.**
`resources/views/components/auth/buttons/grid.blade.php` hardcodes five
`<livewire:auth-buttons-social provider="…">` tags: google, facebook,
discord, github, gitlab. `config/services.php` only has entries for
`google` and `discord`. `github`, `facebook`, `gitlab` have **no config block
at all**. `App\Http\Controllers\Socialite\Login::ensureProviderIsConfigured()`
checks exactly this (`services.keys()->doesntContain($provider)`) and 404s
cleanly — but that check only runs on the OAuth **callback**. The initial
`App\Http\Controllers\Socialite\Redirect` (hit when the button is clicked)
has no such guard and goes straight to `Socialite::driver($provider)->redirect()`,
which fails trying to read a `client_id` out of a config array that doesn't
exist. Three buttons on every login/register/invite page currently error out
when clicked, with no graceful message.

Root cause: **no single source of truth for "which providers exist."** The
provider list lives, redundantly and inconsistently, in:
- `grid.blade.php` (hardcoded 5 `<livewire:...>` tags)
- 5 near-identical Blade views (`resources/views/livewire/auth/buttons/{google,github,discord,facebook,gitlab}.blade.php`) — differ only in icon, label, hover color
- `config/services.php` (only 2 of the 5 actually present)

Compounding it: `App\Livewire\Auth\Buttons\Social` is a full Livewire
component whose `login()` action does nothing but compute a URL from data
already available at render time (`$provider`, the `$invitation` prop,
`tenancy()`/`tenant()`) and redirect. There's no server state, no
validation, nothing that needs a round-trip — every click costs a Livewire
AJAX request purely to produce an HTTP redirect a plain `<a href>` could do
directly. It's registered under a hand-picked alias
(`Livewire::addComponent(name: 'auth-buttons-social', ...)` in
`AppServiceProvider`) purely so `grid.blade.php` has a flatter tag to call.
And `google.blade.php` inlines a raw duplicate of the Google SVG that
`resources/views/components/icons/google.blade.php` already provides — every
sibling file (`github`, `discord`, `facebook`, `gitlab`) correctly reuses its
`<x-icons.*>` component; only `google.blade.php` didn't.

## Fix

Collapse the whole rendering path to one Blade partial driven by one
provider list, with no Livewire component in the click path.

### 1. One provider list, one place

Add a `providers` map to `config/auth.php`'s existing `'social'` block
(alongside the `routes` key already there):

```php
'social' => [
    'routes' => [ ... ],           // unchanged
    'providers' => [
        'google'   => ['label' => 'Google',   'hover' => 'hover:bg-blue-500/10 dark:hover:bg-blue-400/15'],
        'github'   => ['label' => 'GitHub',   'hover' => 'hover:bg-gray-500/10 dark:hover:bg-gray-400/15'],
        'discord'  => ['label' => 'Discord',  'hover' => 'hover:bg-indigo-500/10 dark:hover:bg-indigo-400/15'],
        'facebook' => ['label' => 'Facebook', 'hover' => 'hover:bg-blue-500/10 dark:hover:bg-blue-400/15'],
        'gitlab'   => ['label' => 'GitLab',   'hover' => 'hover:bg-orange-500/10 dark:hover:bg-orange-400/15'],
    ],
],
```

This is metadata (label/styling), not credentials — `config/services.php`
stays the sole source of truth for "is this provider actually usable."

### 2. `grid.blade.php` filters by what's actually configured

Replace the 5 hardcoded `<livewire:...>` tags with a loop over
`config('auth.social.providers')`, **filtered to keys present in
`config('services')`** — the exact same test
`Login::ensureProviderIsConfigured()` already applies on the callback side,
just applied before rendering instead of after clicking:

```blade
@php
    $providers = collect(config('auth.social.providers'))
        ->only(array_keys(config('services')));
@endphp

<div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
    @foreach ($providers as $key => $meta)
        <x-auth.buttons.social :provider="$key" :invitation="$invitation" class="{{ $meta['hover'] }}">
            <div class="flex items-center">
                <x-dynamic-component :component="'icons.'.$key" />
                <span>{{ $meta['label'] }}</span>
            </div>
        </x-auth.buttons.social>
    @endforeach
</div>
```

With today's `config/services.php` (google, discord only), this alone stops
rendering the 3 broken buttons — the actual bug fix. Adding a provider later
means adding one config entry to each of `services.php` and
`auth.php`'s new `providers` map; no new Blade file, no new Livewire class,
no touching `AppServiceProvider`.

### 3. `x-auth.buttons.social` builds the link directly, no Livewire

`resources/views/components/auth/buttons/social.blade.php` gains `provider`
and `invitation` props and computes the same href
`App\Livewire\Auth\Buttons\Social::login()` used to build server-side, using
the same `route('oauth', [...])` shape `Redirect::__invoke()` already reads
from (`driver`, optional `tenant`, optional `invitation`):

```blade
@props(['provider', 'invitation' => null])
@php
    $params = ['driver' => $provider];
    if (tenancy()->initialized) {
        $params['tenant'] = tenant()?->id;
    }
    if ($invitation) {
        $params['invitation'] = $invitation->id;
    }
@endphp
<flux:button
    as="a"
    :href="route(config('auth.social.routes.redirect.name'), $params)"
    {{ $attributes->class('group w-full flex items-center justify-center gap-2 ...') }}
>
    {{ $slot }}
</flux:button>
```

A click is now a normal navigation — no `wire:click`, no component boot, no
AJAX round-trip.

### 4. Delete what's no longer needed

- `app/Livewire/Auth/Buttons/Social.php`
- `resources/views/livewire/auth/buttons/{google,github,discord,facebook,gitlab}.blade.php`
- The `Livewire::addComponent(name: 'auth-buttons-social', ...)` block in
  `app/Providers/AppServiceProvider.php`

The google-icon duplication disappears as a side effect — the loop uses
`<x-icons.google/>` like every other provider already did.

## Out of scope

- `App\Http\Controllers\Socialite\Redirect` / `Login` — untouched; this plan
  only changes how the buttons are *rendered*, not the OAuth
  redirect/callback logic fixed in the previous pass.
- Adding config for the 3 currently-unconfigured providers (github, facebook,
  gitlab) — not requested; they simply stop rendering until someone adds
  real OAuth app credentials for them.

## Verification

- New feature test (no existing test covers this component at all) hitting
  the login page HTML: assert it contains a link to
  `route('oauth', ['driver' => 'google'])` and to `'discord'`, and does
  **not** contain `'driver=github'` / `'facebook'` / `'gitlab'` — proves the
  filter actually suppresses the unconfigured ones.
- Manually load `/login` and `/register` in the browser: only Google +
  Discord buttons render, and clicking one still redirects to the provider
  (same behavior as before for the two that worked).
- `vendor/bin/sail bin pint --dirty --format agent` after edits.
- Grep the codebase for `Buttons\\Social` / `auth-buttons-social` after
  deleting, to confirm nothing else references the removed Livewire
  component.
