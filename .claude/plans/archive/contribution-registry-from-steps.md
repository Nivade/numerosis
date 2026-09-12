# Derive the contribution registry from the steps that use it

**Status: executed.** Written 2026-09-12 on branch
`refactor/derive-contribution-registry`. Four phases, each independently
landable and revertable. Phases 1–3 are the point; phase 4 is a rename that
can be dropped without harming the rest. All four phases landed, one commit
each, 2026-09-12.

Goal: delete `numerosis.tenancy.provisioning.contributions` from
`config/numerosis.php`. A host that adds a column-backed contribution should
declare it on the step that uses it, and nowhere else.

## Why the config key exists today

`TenantProvision::allContributions()` (`src/Models/Central/TenantProvision.php:218`)
is its only reader. The `contributions` JSON blob is self-describing — keyed by
class — but a set of columns is not, so something has to say which contribution
owns `global_id`, `stripe_subscription_id`, `custom_domain`. That something is
currently a hand-maintained config list.

## Rejected: stamp a marker at write time

Have `applyContributions()` record the class name in the JSON blob alongside the
columns, making the row self-describing. It fails because column writes do not
all go through `applyContributions()`, and several cannot: they are
query-builder updates with no model instance, or `firstOrCreate` attributes.

- `src/Actions/Billing/Checkout/SettleCheckout.php:39` — `$pending->update([...])`, mixes contribution columns with `status`/`settled_at`
- `src/Http/Controllers/Billing/WebhookController.php:83` — same shape
- `src/Actions/Billing/Checkout/CreateInlineSubscription.php:86,94` — `$pending->update(['stripe_subscription_id' => …])`
- `src/Actions/Tenancy/ReserveTenantDomain.php:42` — `firstOrCreate` attribute array
- `src/Services/Billing/InlineCheckoutGateway.php:63` — `where(…)->update([…])`, deliberately owner-scoped

Merging a marker into a JSON column from a blind query update needs a read
first, which would cost `InlineCheckoutGateway` the scoped single-statement
write that stops one user overwriting another's SetupIntent. Not worth it.

## Rejected: derive from `consumes()` alone

`ConsumesContributions::consumes()` means "skip this step when absent". It is
not "this step reads this". `CreateTenant` reads `CustomDomainContribution`
(`src/Actions/Tenancy/CreateTenant.php:43`) and must never be skipped when a
tenant has no custom domain. Union-of-`consumes()` would either miss the
contribution or make tenant creation skippable.

Hence phase 1: a second, weaker declaration.

## Phase 1 — `ReadsContributions`

New `src/Contracts/Tenancy/ReadsContributions.php`:

```php
interface ReadsContributions extends ProvisioningStep
{
    /**
     * @return list<class-string<ProvisionContribution>>
     */
    public static function reads(): array;
}
```

Docblock (one short paragraph, per `.ai/rules/general.md` limits) states the
distinction: interest without dependency. The runner never skips on it.
`ConsumesContributions` already implies interest, so a step implementing both
is legal but redundant — say so in one line.

`src/Actions/Tenancy/CreateTenant.php`: implement it, return
`[CustomDomainContribution::class]`. No other change to that class.

Nothing behaves differently yet.

**Verify:** `vendor/bin/pint --dirty --format agent`, `composer analyse`.

## Phase 2 — registry derived from configured steps, config key deleted

`src/Models/Central/TenantProvision.php`:

```php
/**
 * @return list<class-string<ProvisionContribution>>
 */
private static function columnBackedContributions(): array
{
    /** @var list<class-string> $steps */
    $steps = Config::array('numerosis.tenancy.provisioning.steps', []);

    $declared = [];

    foreach ($steps as $step) {
        $declared = [
            ...$declared,
            ...(is_a($step, ConsumesContributions::class, true) ? $step::consumes() : []),
            ...(is_a($step, ReadsContributions::class, true) ? $step::reads() : []),
        ];
    }

    return array_values(array_unique(array_filter(
        $declared,
        static fn (string $c): bool => is_a($c, PersistsToProvisionColumns::class, true),
    )));
}
```

`allContributions()` reads that instead of
`Config::array('numerosis.tenancy.provisioning.contributions')`. Keep the
existing blob loop and the `array_filter` on nulls; keep dedupe so a
column-backed class declared by two steps yields one instance. Rewrite its
docblock — the "a registry is needed only for the column-backed half" line is
still true, but the registry is now derived, not configured.

`config/numerosis.php`: delete the `contributions` key (lines 376–387 of the
current file) and its comment block. Drop the now-unused `OwnerContribution`,
`BillingContribution`, `CustomDomainContribution` imports if nothing else in
the file names them — check, the file is long.

Coverage check before landing, all three core column-backed contributions must
be reachable:

| Contribution | Declared by |
|---|---|
| `OwnerContribution` | `AddTenantOwner::consumes()`, `PromoteFirstUserToAdmin::consumes()` |
| `BillingContribution` | `LinkTenantSubscription::consumes()` |
| `CustomDomainContribution` | `CreateTenant::reads()` (phase 1) |

**Known consequence, document it rather than fix it:** a column-backed
contribution no step declares is invisible to `allContributions()`. That is the
intended trade — the declaration moves onto the step — but it is a silent
failure mode, so phase 3's docs row has to state it plainly.

### Tests

`tests/Feature/Data/Tenancy/ProvisionContributionTest.php` — add:

- `test_a_column_backed_contribution_declared_only_by_a_reading_step_survives_the_round_trip()`: write a `CustomDomainContribution` via `applyContributions()`, save, `TenantProvisionData::fromProvision()`, assert `contribution(CustomDomainContribution::class)?->custom_domain`. Fails without phase 1 + 2 once the config key is gone.
- `test_a_host_column_backed_contribution_is_discovered_through_its_step()`: needs a column-backed test contribution. `tests/Support/SeatCountContribution.php` is blob-based — leave it alone and add `tests/Support/CustomDomainReadingStep.php`-style support only if a new contribution class is cheap; otherwise assert the host case through `RecordSeatCountStep` + `SeatCountContribution` staying blob-discovered (no regression) and cover the column-backed host path by temporarily appending `CreateTenant` to a custom steps list.

Prefer the smaller shape: if adding a column-backed test contribution means a
test-only migration, skip it and say so in the plan's execution notes.

`tests/Feature/Jobs/RunProvisioningStepTest.php` — add one asserting a step
implementing only `ReadsContributions` still runs when the contribution is
absent (the `CreateTenant`-must-not-skip invariant, made explicit).

**Verify:** `docker start numerosis-mysql-1` first, then
`vendor/bin/pest --filter=ProvisionContribution`,
`vendor/bin/pest --filter=RunProvisioningStep`, then `composer test`.

## Phase 3 — docs and rules

- `docs/extending.md:174` — the "make a contribution's fields queryable" row drops "and list the contribution's class under `numerosis.tenancy.provisioning.contributions`", gains "and declare it on the step that uses it, through `ConsumesContributions::consumes()` or `ReadsContributions::reads()` — a column-backed contribution no step declares will not be rebuilt off the row".
- `docs/extending.md:171` — add a sibling row for `ReadsContributions`: read a contribution without becoming skippable.
- `.ai/rules/tenant-provisioning.md:45-52` — rewrite the contributions bullet: the column-backed half is discovered from the configured steps' declarations now, and the two declarations mean different things. Keep the `OwnerContribution`/`global_id` bullet at `:92` as is.
- `docs/host-requirements.md` — grep for the config key; if it enumerates `numerosis.tenancy.provisioning` keys, drop the removed one.

Grep for stragglers: `grep -rn "provisioning.contributions" . --include="*.php" --include="*.md" --exclude-dir=vendor --exclude-dir=node_modules`.

## Phase 4 — optional rename, separate commit

With `reads()` next to it, `consumes()` reads as a near-synonym when it in fact
means "required, skip without it". Rename for the distinction:

- `src/Contracts/Tenancy/ConsumesContributions.php` → `RequiresContributions.php`, `consumes()` → `requires()`
- Callers: `src/Jobs/RunProvisioningStep.php:83,88`, `src/Actions/Tenancy/AddTenantOwner.php:35`, `src/Actions/Tenancy/PromoteFirstUserToAdmin.php:33`, `src/Actions/Tenancy/LinkTenantSubscription.php:35`, `src/Models/Central/TenantProvision.php` (phase 2's helper)
- `tests/Support/RecordSeatCountStep.php`
- Docs and rules touched in phase 3, plus `src/Contracts/Tenancy/ProvisioningStep.php`'s closing line and `src/Enums/Tenancy/StepOutcome.php:16`

Breaking change with no installs to break, so no deprecation shim. Land it
alone so it can be reverted without losing phases 1–3.

**Verify:** `composer lint`, then `composer test`.

## Execution notes

- Pint after every phase; `composer analyse` before each commit (level 9, cold cache — see `.ai/rules/static-analysis.md`).
- `.ai/rules/general.md` comment caps apply to every docblock written here: no paragraph comments, no citing `.ai/rules` or `docs/` from source.
- One commit per phase. Suggested subjects: `feat: add ReadsContributions for steps that read without depending`, `refactor: derive the column-backed contribution registry from the steps`, `docs: describe contribution discovery through step declarations`, `refactor: rename ConsumesContributions to RequiresContributions`.
- When executed, `git mv` this file into `.claude/plans/archive/` and update the Live table in `.claude/plans/README.md` in the same pass.
