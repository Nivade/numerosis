# Dedupe LoginUser guard-resolution logic

**Status: ✅ Executed.** Confirmed via `.claude/rules/auth-login.md`:
`LoginUser::handle()`'s two copy-pasted resolve blocks extracted into
`resolveUserForGuard()` + `loginToGuard()`, both call sites now share one
path.

## Context

User asked for a redundancy/duplication pass over `app/Actions/Auth/`. Read
all 14 action files plus the concerns they lean on (`TenancyAwareGuard`,
`TenancyAwareUserModel`, `App\Concerns\Auth\ThrottlesLoginAttempts`) and the
two Livewire login components that consume them.

Most of the directory is already deliberately factored: the
`Creates`/`Resolves`/`Authenticates`/`Sends` contracts in `App\Contracts\Auth`
exist specifically as swap points (documented on each interface), so
`AuthenticateLoginCandidate` and `SendEmailVerificationNotification` being
thin one-line delegations is the intended shape, not duplication.
`ConnectSocialAccount`/`DisconnectSocialAccount` look similar but do
different things (firstOrCreate+flag vs delete-count) — natural symmetry, not
copy-paste. The login-throttle duplication between `Login` and
`PasswordlessLogin` was already extracted into
`App\Concerns\Auth\ThrottlesLoginAttempts` in an earlier session (see
`.claude/rules/auth-login.md`).

The one real duplicate is inside `App\Actions\Auth\LoginUser`:
`handle()` and `loginToCentralGuardIfNecessary()` each independently do:
resolve the guard → call `getProvider()` with a `throw_if(null)` guard → read
`getModel()` → check `$user instanceof $expectedModel` → fall back to
`userResolver()`. Same five steps, twice, differing only in which guard name
feeds them. This is exactly the shape `.claude/rules/auth-login.md` already
flags as a repeat offender in this codebase (protections/logic drifting
between near-identical copies).

## Change

`app/Actions/Auth/LoginUser.php`:

- Extract the five-step block into `protected function resolveUserForGuard(string $guardName, User $user): User`, containing the guard lookup, `getProvider()`/`throw_if` guard, `expectedModel` check, and fallback to `userResolver()`. Keep the existing `@phpstan-ignore method.notFound` comment on the `getProvider()` call.
- Add `protected function loginToGuard(string $guardName, User $user, bool $remember): void` = `Auth::guard($guardName)->login($this->resolveUserForGuard($guardName, $user), $remember)`.
- Rewrite `handle()` to call `loginToGuard($guardName, $user, $remember)`, then (if the current guard isn't the central guard) `loginToGuard($centralGuard, $user, $remember)`, then `Session::regenerate()`.
- Delete `loginToCentralGuardIfNecessary()` — folded into the above.
- Keep `userResolver()` and `contextForGuard()` unchanged, including the existing doc comment on `userResolver()` explaining why the guard being logged into (not ambient tenancy state) must decide which model to resolve — that reasoning now applies uniformly since both call sites go through the same `resolveUserForGuard()`.
- Error message becomes uniformly `"No provider found for guard: {$guardName}"` (previously the central-guard branch said "central guard" — not asserted by any test, grepped to confirm, and still accurate since `$guardName` is `$centralGuard` in that call).

No behavior change: same two guards get logged into in the same order, same fallback resolution via `FindUserByGlobalId`, same session regeneration at the end.

## Verification

Run the existing regression coverage, no new test needed since behavior is unchanged:

```
vendor/bin/sail artisan test --compact --filter=LoginUserTest
vendor/bin/sail artisan test --compact --filter=PasswordlessLoginTest
```

Then `vendor/bin/sail bin pint --dirty --format agent` on the touched file.

## After implementation: update rules

Add a short entry to `.claude/rules/auth-login.md` (near the other
`LoginUser` bullet) noting the twin guard-resolution block was extracted into
`resolveUserForGuard()`/`loginToGuard()`, so a future third guard (or a
change to the resolution logic) has one place to change instead of two that
can drift — same class of issue the file already documents twice for this
codebase.
