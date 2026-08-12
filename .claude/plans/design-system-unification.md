# Design System Unification — main app + 2 Filament panels

**Status:** Phases 0–8 done, including Phase 7's browser-only items — verified live via a real Chrome session against thin-app on 2026-08-12, see that phase's own writeup below for what was found and fixed. Old per-user Phase 5 scaffolding removed and replaced with consumer-level override docs
**Date:** 2026-08-07
**Repo:** `nvade/numerosis` (package repo, Testbench harness — *not* thin-app)

---

## Decisions taken up front

| Question | Decision |
|---|---|
| Customization level | **Package consumer (host app) overrides tokens at build time — no end-user or per-tenant runtime picker.** Tenant branding module (`nvade/branding`) stays a separate, existing, untouched feature. |
| Delivery | **Full 8 phases, staged** — each phase lands + tested + approved before the next |
| Filament theming | **CSS custom properties**, not `FilamentColor::register()` |
| Design authority | **`resources/views/*`** — the marketing, legal and product pages. Where a component under `resources/views/components` disagreed with them, the pages won. |

---

## Phase 0 — Reconcile the components with the pages (**done**)

The `ui/` component library had drifted from the pages it serves. Fixed before
any token work, because tokens extracted from a library that contradicts the
pages would encode the contradiction.

**Canonical language, read off the pages:**

| Concern | Value |
|---|---|
| Neutral ramp | zinc, exclusively |
| Page background | `bg-white dark:bg-zinc-800` |
| Surface | `bg-white dark:bg-zinc-900` — recessed below the page |
| Border | `border-zinc-200 dark:border-white/10` |
| Radius | `lg` small · `xl` cards · `2xl` media/hero · `full` pills |
| Accent | blue-500 |
| Semantic | positive emerald · warning amber · danger red · info blue |
| Body copy | `text-zinc-600 dark:text-zinc-300` |
| Muted copy | `text-zinc-500 dark:text-zinc-400` |
| Section rhythm | `py-12 sm:py-20`, container `max-w-7xl` (or `max-w-4xl` prose), `px-4 sm:px-6 lg:px-8` |
| Hero heading | `text-4xl sm:text-5xl font-extrabold tracking-tight` |

**What changed:**

- `ui/card` — surface was `dark:bg-zinc-800`, identical to the body every
  layout sets, so a dark-mode card had no surface at all and only its border
  separated it from the page. Now recessed to zinc-900, hairline border,
  `rounded-xl`.
- `ui/alert`, `ui/badge`, `ui/info-box` — three disagreeing semantic palettes
  (green/yellow, green/yellow, green/amber) for one set of meanings. `ui/alert`
  is now the single callout implementation; `ui/info-box` delegates to it and
  keeps `danger` as an alias for `error` so its callers don't change.
- `ui/alert` — four inline SVG paths replaced with `<flux:icon>`, the icon
  mechanism every other component uses. Focus ring moved to `focus-visible` on
  the accent rather than `focus:ring-current`.
- `ui/empty-state`, `ui/heading`, `ui/subheading`, `feature-line` — gray ramp
  removed. `ui/text` had `muted` and `subtle` crossed in dark (the quieter
  variant was the lighter one); realigned to the pages.
- `ui/stepper`, `ui/auth-session-status`, `feature-line` — green → emerald.
- `ui/heading` gained a `display` size carrying the page hero treatment;
  `level`→size mapping deliberately unchanged so existing headings don't move.

**New components**, extracted from patterns the pages repeat by hand:

- `ui/hero-gradient` — the radial wash, `bold` / `soft`. **Fixes a live bug:**
  `welcome.blade.php` wrote the stop as `var(--color-blue-500)/15`, which is
  not a valid gradient stop, so that page rendered no wash at all. Now
  `color-mix(in oklab, …)`.
- `ui/icon-tile` — the tinted `bg-{color}-500/10` icon square. Colours are a
  closed allowlist because Tailwind only generates classes it can find spelled
  out in source; a class assembled from an interpolated prop never gets built.
- `ui/section` — container and vertical rhythm, `wide` / `prose`.

Applied across `welcome`, `about`, `features`, `privacy`, `terms`; the six
duplicated card divs in `welcome` now use `ui/card`.

**Verification:** `tests/Feature/View/Components/UiDesignLanguageTest.php`,
22 tests. Confirmed to **fail 12 against the pre-fix components** before
passing against the fixed ones — a test that only ever ran green would prove
nothing about this class of drift. Full suite: 443 passed, 7 skipped, 1 failure
(`RegisterTenantTest`, pre-existing and recorded in `b897052`; reproduced with
these changes stashed). `pint` clean; `phpstan` unchanged at 4 files, none of
them touched here.

**Deliberately left alone:** `resources/views/components/billing/*` and the
registration wizard steps still carry the gray ramp, green, and
`dark:border-zinc-700`. That is the Phase 3 volume sweep; doing part of it now
would leave the tree half-migrated and harder to reason about than either end.
`features.blade.php`'s media panels also keep their literal `rounded-2xl`
wrapper rather than becoming `ui/card` — merging `rounded-2xl` onto a component
that already emits `rounded-xl` is a specificity coin-flip, not a reuse win.

---

## Phase 1 — Audit (done; findings below)

### 1.1 The constraint that shapes everything: this is a package, not an app

There is no `package.json` and no `vite.config.js` here. The Tailwind/Vite build lives in the **host** (thin-app). The package ships `resources/css/{app.css,filament/...}` and `resources/js/*`, and `InstallNumerosisCommand::publishAssets()` publishes them into the host under tag `numerosis-assets` (`src/Commands/InstallNumerosisCommand.php:131`).

Publishing means **the host owns a copy**. The install command already ships a drift check that warns when published assets differ from package originals (`InstallNumerosisCommand.php:693`). So:

> **Design rule:** design tokens must live in a package-owned CSS file that the host's published `app.css` `@import`s out of `vendor/`. Tokens then upgrade with `composer update`. If tokens are written *into* the published `app.css`, every existing host freezes at whatever version it published at, and the drift warning fires on every install forever.

### 1.2 Main application — how it is built

> Written before the design authority was pinned to `resources/views/*`. It is
> accurate as a description of the *stack*; the authority on visual
> **conventions** is Phase 0's table, not this section.

- **Stack:** Livewire 4 + **Flux UI v2** (`livewire/flux`, `require-dev` — see `.claude/rules/testing.md` on why) + Tailwind v4.
- **`resources/css/app.css` is 14 lines.** Whole thing: `@import 'tailwindcss'`, `@import` Flux's dist CSS, four `@source` directives, `@variant dark (&:where(.dark, .dark *))`, and one token — `--font-sans: 'Instrument Sans'`. **That is the entire design system today.**
- **Fonts:** Instrument Sans, weights 400/500/600, loaded from `fonts.bunny.net` in `resources/views/partials/head.blade.php`. No display face, no mono face.
- **Dark mode:** class-based on `<html>`, driven by Flux's `@fluxAppearance` (localStorage). `resources/views/partials/styles.blade.php` emits `@vite(app.css, app.js)` + `@fluxAppearance` + `@livewireStyles`.
- **Appearance UI already exists** — `resources/views/livewire/settings/appearance.blade.php`, routed at `settings/appearance` (`routes/web.php:57`). It is a 3-way segmented radio bound to `$flux.appearance`. **It persists to localStorage only.** Nothing server-side, nothing that reaches the panels.
- **Layouts:** `layouts/app.blade.php` (Flux `<flux:main>`), with `layouts/app/{header,sidebar,none}.blade.php` variants. Sidebar uses `flux:sidebar` / `flux:navlist`.
- **Hand-rolled component library:** `resources/views/components/ui/` — `card`, `badge`, `alert`, `empty-state`, `heading`, `subheading`, `text`, `stepper`, `avatar`, `grid`, `info-box`, `tooltip/`, `list/`, `sheet/`, `accordion/`, `dropdown-menu/`, `action-message`, `auth-session-status`. Plus `components/card/stat.blade.php`.

### 1.3 Hardcoded values — the actual mess, quantified

Measured at audit time, then re-measured after Phase 0. The delta is what
Phase 0 actually removed; the **after** column is the size of the Phase 3
sweep still to come.

| Symptom | At audit | After Phase 0 |
|---|---|---|
| Blade views carrying an off-ramp palette class | **57 of 110** | **31 of 113** |
| `zinc-*` occurrences | 333 | 350 |
| `gray-*` occurrences | 179 | **164** |
| `neutral-*` occurrences | 16 | **15** |
| `stone-*` occurrences | 3 | 3 |
| Distinct `rounded-*` values | **8** (`lg` 35, `full` 20, `xl` 17, `2xl` 15, `md` 11, `sm` 10, `3xl` 3, `bl` 1) | 8 (`lg` 30, `full` 20, `2xl` 15, `xl` 13, `sm` 10, `md` 10, `3xl` 3, `bl` 1) |

Phase 0 moved the component *library*; the remaining gray is concentrated in
`components/billing/*` and the registration wizard steps, which is why the
count dropped by only ~26 views. **Radius is untouched as a distribution** —
Phase 0 fixed which radius `ui/card` emits, but the eight-value spread is a
token problem, not a component problem, and Phase 2 is where it collapses.

**Four competing grey ramps.** `zinc` wins on volume and is Flux's own default — it becomes canonical. `gray`'s 179 uses are the single largest mechanical edit in this project.

**Semantic colors are duplicated by hand, inconsistently:**
- `ui/badge.blade.php` maps success/warning/error/info to `green-100/800`, `yellow-`, `red-`, `blue-`, and `default` to **`gray-`**.
- `ui/alert.blade.php` maps the same four to a *different* set (`green-50` + `border-green-200` + `text-green-800` + separate icon and button colors) — a five-key style array per variant, none of it shared with `badge`.
- `ui/empty-state.blade.php` mixes `zinc-400` (icon) and `gray-700`/`gray-600` (text) **in one file**.
- `ui/alert.blade.php` inlines four raw SVG paths for its icons while the rest of the codebase uses `<flux:icon>`.

**No spacing, shadow, border-width, transition, or z-index scale exists anywhere.**

### 1.4 Panel 1 — `admin` (central)

- Registered by `Nvade\Numerosis\Filament\NumerosisAdminPlugin`, `->id('admin')`, `->path('admin')`.
- Host sets `'primary' => Color::Amber` (`workbench/app/Providers/Filament/AdminPanelProvider.php:46`).
- **No `->viteTheme()`. No render hooks. Nothing from the main app reaches it** — no Flux CSS, no Instrument Sans, no `.dark` class wiring. It is stock Filament with an amber tint.

### 1.5 Panel 2 — `tenantAdmin`

- `NumerosisTenantPlugin`, `->id('tenantAdmin')`, `->path('/')`, `->spa()`, tenant-domain routed.
- Host sets `'primary' => Color::Rose` (`TenantAdminPanelProvider.php:41`).
- **Already injects the main app's stylesheet** via `PanelsRenderHook::STYLES_AFTER` → `@include('numerosis::partials.styles')`, and `SCRIPTS_AFTER` → `@fluxScripts` (`NumerosisTenantPlugin.php:157-167`). **This is the existing seam to build on** — the mechanism for getting shared CSS into a panel is already proven here, just not extended to `admin` and not carrying any tokens.
- Also has a `CONTENT_START` hook rendering the payment-status banner.

**So the three surfaces are currently amber, rose, and zinc.** Nothing shared.

### 1.6 `resources/css/filament/tenantAdmin/theme.css` — dead file, delete it

174 lines, **entirely commented out** (every rule wrapped in `/* */`). It is not referenced by any `->viteTheme()` call anywhere in the repo. Its `@source` paths point at `app/Filament/**` and `../../../../vendor/filament/filament/...` — host paths that do not exist from the package's location. Its commented-out content is also a *contradictory* design direction: `rounded-none` everywhere, `font-size: 15px` on `:root`, `h-9` inputs — i.e. sharp and compact, the opposite of the main app's `rounded-lg` + `p-6` cards.

It is a decoy. Someone will read it as "the Filament theme" and it does nothing.

### 1.7 Existing per-tenant branding (precedent + a real constraint)

`nvade/branding` is an optional, **thin-app-owned** app-module (see `.claude/rules/module-marketplace.md`). Its `ApplyBranding` middleware is registered by `BrandingPlugin` only when the tenant has the module enabled, and it overrides panel primary color / logo / favicon, caching the decision under `BrandingCache::panelBranding()`.

Core also has `Nvade\Numerosis\Http\Middleware\ApplyDefaultBranding` (sets `brandName` from `tenant()->name`).

**The constraint, documented in `tests/Feature/Modules/Branding/ApplyBrandingTest.php`'s docblock:**

> `Filament\Support\Facades\FilamentColor`'s `ColorManager::getColors()` **memoises on first call for the life of the container and ignores every later `register()`.**

This is exactly why decision #3 is CSS custom properties. A per-user, per-request color cannot go through Filament's PHP color API reliably. It can go through `<html style="--...">` trivially.

### 1.8 UX divergences beyond CSS

- Main app notifications: `ui/alert.blade.php`, an inline Alpine `x-show` box reading `session('success'|'error'|...)`. Filament: toast notifications via its own notification system. **Two unrelated feedback channels.**
- Main app empty states: `ui/empty-state.blade.php`. Filament: `->emptyStateHeading()` / `->emptyStateIcon()` per table. No shared copy or icon vocabulary.
- Main app loading: Livewire `wire:loading`. Filament: its own loading indicators. No shared spinner.
- Confirmation: main app has ad-hoc modals; Filament uses `->requiresConfirmation()` with its own copy conventions.
- Main app radius vocabulary is 8 values; Filament's is its own `--radius` scale.
- `ui/alert.blade.php` has a `focus:ring-offset-2 focus:ring-offset-transparent` focus ring; nothing else in the codebase declares focus styles — Flux and Filament each supply their own.

---

## Phase 2 — Token layer

### 2.1 Where it lives

New package file: **`resources/css/tokens.css`** — package-owned, never published.

The published `resources/css/app.css` becomes a thin entry point that imports it out of `vendor/`:

```css
@import 'tailwindcss';
@import '../../vendor/livewire/flux/dist/flux.css';
@import '../../vendor/nvade/numerosis/resources/css/tokens.css';
```

`tokens.css` declares tokens in a bare `:root`, redefines only what changes under `.dark`, and exposes them to Tailwind v4 via `@theme inline` so utilities (`bg-surface`, `rounded-md`, `text-muted`) compile from the same variables the runtime overrides.

> **Layering rule, non-negotiable:** every token gets its light value on bare `:root`. `.dark` and preference selectors only *redefine*. A token whose only definition sits inside `.dark` breaks the moment a preference toggles.

### 2.2 Token spec

Three tiers. Only tier 3 is consumed by components.

**Tier 1 — primitives** (`--zinc-50 … --zinc-950`, accent ramps, semantic ramps). Never referenced by a component.

**Tier 2 — consumer inputs.** Overridable by host CSS (Phase 5). Defaults declared here.
```
--pref-accent-hue, --pref-density, --pref-radius, --pref-border, --pref-shadow, --pref-font-scale
```

**Tier 3 — semantic tokens.** The only names any component may use.

| Group | Tokens |
|---|---|
| Surface | `--color-background`, `--color-surface`, `--color-surface-raised`, `--color-surface-sunken` |
| Text | `--color-text`, `--color-text-muted`, `--color-text-subtle`, `--color-text-inverted` |
| Line | `--color-border`, `--color-border-strong`, `--color-ring` |
| Accent | `--color-primary`, `--color-primary-hover`, `--color-primary-contrast`, `--color-primary-subtle` |
| Semantic | `--color-{success,warning,danger,info}` × `{-bg, -border, -text, -icon}` |
| Radius | `--radius-sm/md/lg/xl/full` — all derived from `--pref-radius` |
| Spacing | `--space-1…12`, plus `--space-card`, `--space-section`, `--space-field` — derived from `--pref-density` |
| Type | `--font-sans`, `--font-display`, `--font-mono`; `--text-xs…3xl` derived from `--pref-font-scale`; `--leading-tight/normal/relaxed`; `--weight-normal/medium/semibold` |
| Elevation | `--shadow-xs/sm/md/lg` — scaled by `--pref-shadow` |
| Border width | `--border-width`, `--border-width-strong` — scaled by `--pref-border` |
| Motion | `--duration-fast/base/slow`, `--ease-standard` (all → `1ms` under `prefers-reduced-motion`) |
| Z-index | `--z-dropdown/sticky/overlay/modal/toast/tooltip` |

**Canonical resolutions (main app wins):**
- Grey ramp: **zinc**. `gray-*` (179), `neutral-*` (16), `stone-*` (3) all migrate.
- Card: `--radius-lg` + `--color-surface` + `--color-border` + `--space-card` — from `ui/card.blade.php`'s `default` variant.
- Badge: `--radius-full`, `text-xs`, `--weight-medium` — from `ui/badge.blade.php`.
- Semantic ramp: one source, consumed by *both* badge and alert. Today they disagree; badge's tint level becomes `-bg`, alert's becomes `-bg` at the softer step. One decision, two consumers.

### 2.3 Filament bridge

New package file: **`resources/css/filament-theme.css`**. Imports `tokens.css`, then remaps Filament v5's own custom properties onto tier-3 tokens. Registered on **both** panels via `->viteTheme()`, with the host building it (documented as a required step in `InstallNumerosisCommand::printManualSteps()`).

Both panels also gain the `STYLES_AFTER` render hook the tenant panel already has — extracted from `NumerosisTenantPlugin` into a shared concern so admin and tenant cannot drift.

**No `->colors()` in either panel provider after this.** Amber and Rose are deleted; primary comes from `--color-primary`. The `->colors()` extension point stays available to hosts, but the package stops depending on it.

---

## Phase 3 — Main app refactor

Not a redesign. It is the authority; the work is making it *consume* what it already implies.

1. Replace 4 grey ramps with zinc-backed semantic tokens across 57 views.
2. Collapse 8 radius values to the 5-token scale (`2xl`/`3xl` → `--radius-xl` unless a specific view justifies otherwise).
3. Rewrite `ui/badge.blade.php` + `ui/alert.blade.php` against **one** semantic map.
4. `ui/alert.blade.php`: replace 4 inline SVG paths with `<flux:icon>`, matching every other component.
5. `ui/empty-state.blade.php`: single ramp (currently zinc + gray in one file).
6. Add the missing focus-visible ring as a token-driven utility, applied once rather than per component.

**Guard:** an arch/CSS lint test that fails on raw `gray-|neutral-|stone-` and on `rounded-2xl|3xl` in `resources/views`. Otherwise this regresses within a month.

---

## Phase 4 — Filament panels

1. **Delete `resources/css/filament/tenantAdmin/theme.css`** (§1.6 — dead, contradictory, a decoy).
2. Ship `resources/css/filament-theme.css`; wire `->viteTheme()` on both panels.
3. Extract the tenant panel's `STYLES_AFTER` / `SCRIPTS_AFTER` hooks into a shared concern used by both plugins.
4. Drop `->colors()` from both workbench providers.
5. Load Instrument Sans in the panels (currently main-app-only).
6. Align navigation: Filament sidebar visual weight, group headings, and active state to match `flux:navlist`.
7. Align tables: Filament `fi-ta-*` surfaces to `--color-surface` / `--radius-lg` / `--color-border`.
8. Verify against Filament v5 official extension points only — no fragile descendant selectors that break on upgrade.

---

## Phase 5 — Consumer customization

Target: whoever installs this package into their own app (thin-app, or any
other host) — **not** the end user, **not** a tenant admin. No database
column, no per-request resolution, no settings screen. A host picks its
brand once, at build time, same way it already picks Filament panel colors
today.

### 5.1 Override point

`tokens.css` (Phase 2) is package-owned and never published. The published
entry point (`resources/css/app.css`) is a host-owned file that already
`@import`s it out of `vendor/`. CSS custom properties cascade, so a host
overrides any tier-2/tier-3 token by redeclaring it **after** that import —
no new mechanism, no file format, just documented override points:

```css
@import 'tailwindcss';
@import '../../vendor/livewire/flux/dist/flux.css';
@import '../../vendor/nvade/numerosis/resources/css/tokens.css';

:root {
  --pref-accent-hue: 265;
  --pref-radius: 0.5;
  --font-sans: 'Host Brand Sans';
}
```

Same file, same import, feeds both the main app and — via
`resources/css/filament-theme.css` (Phase 4) — both Filament panels through
`->viteTheme()`. One override, three surfaces, no per-surface config.

### 5.2 What a host can override

Every tier-2/tier-3 token from §2.2 is fair game: accent hue, radius scale,
density, font stack, shadow/border intensity, semantic colors. Nothing here
is enum-gated — it's the host's own CSS file, in the host's own repo, under
the host's own change control. Validity is the host's problem, same as any
other Tailwind override today.

### 5.3 Documentation, not code

This phase ships no new PHP, no migration, no Blade partial. The
deliverable is:
- a documented list of overridable custom properties (§2.2's table, with a
  short description per token), and
- a section in `InstallNumerosisCommand::printManualSteps()` pointing at it.

### 5.4 Explicitly out of scope

- Per-user theme picker, preference storage, or settings UI — not built.
  The existing `settings/appearance` page (dark/light/system, localStorage
  only) is untouched by this plan.
- Per-tenant runtime token overrides — `nvade/branding` already exists for
  tenant-level branding (logo/color) and is not extended here; if a host
  wants tenant admins to pick tokens, that's a host-level feature built on
  top of `nvade/branding`'s existing contract, not something this package
  adds.

---

## Phase 6 — UX normalization

- **One notification channel — done.** Built a shared
  component (not routed onto Filament's own system — that would drag
  Filament's Livewire/Alpine plumbing into marketing/auth pages that never
  load a panel): `resources/views/partials/toasts.blade.php` (an Alpine
  store, fixed top-right, `aria-live="polite"`) plus
  `resources/views/components/ui/toast.blade.php` (one floating card per
  type, same semantic-token palette as `ui/alert`). Every Livewire action
  or redirect dispatches into it via a `notify` browser event
  (`$this->dispatch('notify', type: ..., message: ...)`); every existing
  `redirect()->with('success', ...)`-style flash keeps working with zero
  call-site changes — the four conventional keys (`success`/`error`/
  `warning`/`info`, plus `message` as an `info` alias) are bridged
  server-side into the same Alpine store at first paint. Wired into all
  three main-app layout shells (`layouts/app/{header,sidebar,none}.blade.php`).
  Replaced the registration wizard's ad-hoc `<x-numerosis::ui.alert closable
  />` (the only place a page rendered session flash inline) — that was the
  concrete instance of "two unrelated feedback UIs" this closes.
  `ui/alert` itself is untouched: it remains the callout component for
  explicit inline content (`ui/info-box`, validation-style messaging), a
  different job from a floating toast. Tests: `tests/Feature/View/Components/ToastTest.php`,
  6 tests. Auth layouts (`layouts/auth/*`) deliberately not wired — they
  already have a purpose-built, tested pattern for their one status key
  (`ui/auth-session-status.blade.php`, inline beneath the form, not a
  toast) and mixing the two would leave two mechanisms for one auth flow.

  **Filament-panel half — reskin, not replace (decided after reading
  Filament's actual source).** `Filament\Notifications\Notification::send()`
  has a feature surface `ui/toast` doesn't and shouldn't try to match:
  per-notification icons, colors, durations, action buttons, database
  notifications, and Echo broadcast delivery, all wired through
  `Filament\Notifications\Livewire\Notifications` via Livewire events and
  session (`vendor/filament/notifications/src/Notification.php`,
  `.../src/Livewire/Notifications.php`). Replacing that behaviourally would
  mean either reimplementing all of it or stripping features from every
  existing `->send()` call across both panels — a real regression, not a
  reskin. Instead: left Filament's delivery entirely alone, and confirmed
  the *visual* unification already happened as a side effect of Phase 4.
  Filament's notification card (`vendor/filament/notifications/resources/css/notification.css`)
  resolves its colors through `text-gray-*`, `bg-white`/`dark:bg-gray-900`,
  and `text-color-{name}-400` (from `->color()`), its radius through
  `rounded-xl`, and its shadow through `shadow-lg` — every one of those
  custom properties is already remapped by `filament-theme.css` +
  `tokens.css`. So a Filament toast and a `ui/toast` already render on the
  same palette, radius, and shadow scale; "one channel" is satisfied
  visually without a behavioural merge. No new code needed for this half —
  it was already true once Phase 4 landed, just unverified until now.
- **One empty-state vocabulary — done, smaller than scoped.** Surveying
  every `->emptyStateHeading()`/`->emptyStateIcon()`/`->emptyStateDescription()`
  call across both panels found the copy tone already consistent ("No X
  yet/defined/assigned" + a description explaining why and what to do
  next) — nothing to rewrite there. The one real inconsistency was icon
  references split between raw `'heroicon-o-*'` strings and the
  `Filament\Support\Icons\Heroicon` enum (CLAUDE.md's documented
  convention) across 8 resources/tables; normalized all 8 to the enum.
  Guard added: `DesignLanguageGuardTest::test_no_filament_resource_uses_a_raw_heroicon_string_for_empty_state_icon`.
  `ui/empty-state` (main app) untouched — its icon vocabulary (Lucide-style
  names via `<flux:icon>`) is a different icon set from Filament's
  Heroicons already, by Flux's own design, not a drift to fix.
- **One loading indicator — done, and smaller than scoped.** `<flux:button>`
  already auto-derives `wire:loading.attr` from its own `wire:click` (Flux's
  `button/index.blade.php`), and `<x-filament::button>` does the same *plus*
  renders `Filament\Support\generate_loading_indicator_html()` — a real
  spinner, not just a disabled state (`vendor/filament/support/.../button/index.blade.php`).
  So "shared spinner" was already the case everywhere a page used the
  right button component; the actual gap was two pages
  (marketplace, module-detail) manually adding a redundant
  `wire:loading.attr="disabled"` + `wire:target` on top of
  `<x-filament::button>` — which is strictly worse than what the component
  already does (disables with no spinner, vs. disables with the real one).
  Removed both. Guard added:
  `DesignLanguageGuardTest::test_no_filament_button_manually_duplicates_its_own_loading_indicator`.
  `components/billing/plan-card.blade.php`'s `wire:loading.attr="disabled"`
  on a `<flux:modal.trigger>` button left alone — that button has no
  `wire:click` of its own (it opens a modal; the actual plan-change action
  fires later), so this isn't the same redundancy and touching it risks an
  application-behaviour change, not a design-system one.
- **One confirmation pattern — surveyed, nothing to fix.** All 8
  `->requiresConfirmation()` sites across both panels already use Filament's
  own vocabulary uniformly (`modalHeading`/`modalDescription`/
  `modalSubmitActionLabel`), and the submit label is consistently the
  action itself ("Purchase", "Delete Account", "Disconnect", "Resend
  Invitation") — not a generic "Confirm". Of the main app's 4 ad-hoc
  `flux:modal` usages, 3 are not confirmations at all (password re-entry
  before account deletion, a plan-comparison detail modal, a plan's
  feature-list modal) and the 1 genuine simple confirm (the tenant-list
  page's cancel-reservation dialog) already follows the same
  action-named-button convention ("Keep reservation" / "Cancel
  reservation"). Building a shared confirm-modal component for one
  already-consistent call site would be exactly the premature abstraction
  CLAUDE.md warns against — three similar lines beat an abstraction with
  one real caller.
- **One form-validation presentation — surveyed; a real, minor gap remains,
  accepted rather than patched.** Filament's field-wrapper already renders
  errors through its own themable `--danger-*` custom property
  (`fi-fo-field-wrp-error-message { @apply text-danger-600 dark:text-danger-400 }`,
  `vendor/filament/forms/resources/css/components/field.css`) — already
  remapped onto our red danger ramp by Phase 4's `filament-theme.css`, no
  further work needed. Flux's `<flux:error>` (used by every main-app form
  field) hardcodes literal `text-red-500 dark:text-red-400` instead of
  going through any themable indirection — same red family as our danger
  token, but one Tailwind shade lighter than Filament's in light mode
  (`red-500` vs `danger-600`), and it prepends an `exclamation-triangle`
  icon Filament's own error text doesn't. Both are genuinely vendor-owned
  Blade views; closing this gap would mean forking Flux's `error.blade.php`
  (and the field components that embed it) via Flux's publish mechanism —
  a real "does this package fork vendor views" decision this plan never
  scoped, not something to improvise here. Documented as an accepted,
  minor, known gap rather than worked around with something fragile.

---

## Phase 7 — Responsive + accessibility

- **Accent contrast — done, with an honest bound rather than the guarantee
  originally scoped here.** Found `--pref-accent-hue` was declared in
  tokens.css but never consumed anywhere — `--color-primary` referenced a
  static `var(--accent-500)`, so overriding the hue (as
  `InstallNumerosisCommand`'s manual steps tell a host to do) silently did
  nothing. Fixed: `--color-primary`/`-hover` in tokens.css, and the full
  `--primary-50..950` ramp in filament-theme.css, now derive from
  `oklch(L C var(--pref-accent-hue))` using Tailwind's own blue L/C values
  per shade (verified against `node_modules/tailwindcss/theme.css`) — at
  the default hue (259.815, corrected from an HSL-space 217 that was
  simply wrong once anything started reading it) this reproduces
  blue-500/600 exactly, zero visual change to Phase 0's canonical accent.

  **"Always clears WCAG AA" turned out not to be achievable without
  darkening the default away from blue-500** — simulated actual WCAG
  contrast (OKLCH → linear sRGB → relative luminance) across all 360 hues
  at blue-500's own L/C and found the *default* hue itself only clears
  3.76:1 white-on-primary (passes the 3:1 large-text/non-text threshold,
  not full 4.5:1 text AA), and the worst hues in that band (roughly
  130–260°, the green/cyan/blue range) drop to ~2.9:1. Guaranteeing 4.5:1
  at every hue needs L≈0.51, which would render the default accent as
  `#135ed0` instead of `#2b7fff` — a real, visible change to the
  canonical color this same plan fixed in Phase 0. Decided (explicit
  choice, not a default): keep blue-500 exact, document the bound instead
  of enforcing it. `--color-primary-contrast` stays `#fff`, and both
  `tokens.css`'s own comment and `InstallNumerosisCommand`'s manual step 6
  now say plainly that a custom `--pref-accent-hue` is not
  contrast-verified — check it yourself before shipping it. Verified via
  `DesignTokensTest::test_color_primary_is_parameterized_by_pref_accent_hue`
  and `test_filament_theme_gray_ramp_is_zinc_and_primary_ramp_is_hue_parameterized`.
- **Focus-visible ring — done.** Found two hand-rolled exceptions to the
  shared `focus-ring` utility Phase 0 already established: the OAuth
  buttons (`focus-visible:ring-black/15` — a subtle neutral ring,
  presumably to avoid clashing with third-party logos, but never actually
  decided as an intentional exception) and a Filament tenant-admin view
  (`focus-visible:ring-primary-500`, a raw Tailwind ring instead of the
  utility). Both normalized to `focus-ring`. Guard added:
  `DesignLanguageGuardTest::test_no_view_hand_rolls_its_own_focus_visible_ring`.
- **Keyboard nav through Filament (sidebar, tables, modals; tab order,
  focus trapping) — done, verified live 2026-08-12 against real Chrome
  (browser-use, CDP) driving thin-app.** Sidebar tab order on both panels
  matches visual order exactly (skip-link → logo → search → notifications →
  user menu → nav items top-to-bottom, correctly skipping non-focusable
  group labels). Modal focus trap (Filament's own action-modal Alpine
  mechanism) correctly cycles Tab between Cancel/Confirm without leaking
  focus to the page behind it, and Escape/Cancel correctly restores focus to
  the trigger — **for real mouse or real keyboard activation**. One false
  positive worth recording so it isn't rediscovered the hard way: opening
  the modal via a **synthetic, non-trusted `element.click()`** (the obvious
  shortcut when scripting a browser test) reliably breaks Filament's own
  `rememberPreviouslyFocusedElement()`/`restorePreviouslyFocusedElement()`
  pair (`vendor/filament/actions/resources/js/components/modals.js`) — focus
  lands on `<body>` after close instead of back on the trigger. Confirmed
  this does **not** reproduce with a real `click_at_xy` mouse click or a
  real Tab-then-Space keyboard activation, tried both against the same
  action, same page, same session. No fix applied — there's nothing to fix,
  since no real user input path exercises it. **Browser-test methodology
  note for next time**: script the interaction the way a user would produce
  it (real coordinates, real key events), not `element.click()`, when the
  thing under test is anything to do with focus.
- **Target sizes ≥ 44px — done, coarse-pointer only, and smaller in
  practice than "at every density" implied.** Audited whether
  `--pref-density` actually affects real component height and found it
  doesn't — Flux's `xs`/`sm` button sizes (`h-6`/`h-8`, 24px/32px) and
  Filament's equivalents are hardcoded height classes with no relationship
  to the density token (density scales spacing, not component size), so
  they sit below the 44px floor at every density equally, not specifically
  at compact. Added a `@media (pointer: coarse)` rule in tokens.css
  enforcing `min-height`/`min-width: 44px` on real interactive controls
  (`button`, `a[href]`, form controls, `[role=button]`, focusable
  `[tabindex]`) — `min-width`/`min-height` are no-ops on plain inline
  elements per the CSS spec, so this reaches button-styled controls
  without turning inline body-copy links into boxes, and it's
  coarse-pointer-scoped so desktop's compact density is untouched. Guard:
  `DesignTokensTest::test_tokens_css_enforces_a_44px_touch_target_floor_on_coarse_pointers`.
- **`prefers-reduced-motion` — done, and turned out to need more than the
  existing token collapse.** The `--duration-*` collapse to `1ms` already
  existed (Phase 2) but had zero consumers — audited resources/views and
  found every real transition uses Tailwind's own literal `duration-*`
  utilities (`duration-300` compiles to a fixed `300ms`; Tailwind v4 has no
  themeable duration namespace), so the token collapse alone protected
  nothing. Added a universal `*, *::before, *::after` reset forcing
  `animation-duration`/`transition-duration: 1ms !important` and
  `scroll-behavior: auto` under the same media query — the standard
  pattern, and what actually honours the preference regardless of which
  mechanism produced the motion. Guard:
  `DesignTokensTest::test_reduced_motion_resets_every_transition_not_just_the_dead_tokens`.
- **Filament panels at mobile widths — done, verified live 2026-08-12 the
  same way.** Admin dashboard, Tenants table, tenant panel dashboard all
  clean at 375px (CDP device + touch emulation): body-level
  `scrollWidth === clientWidth` (no horizontal scroll), mobile sidebar
  becomes a proper 320px fixed off-canvas drawer (`z-index: 30`, hamburger
  toggle correctly sized at the 44px coarse-pointer floor Phase 7's own
  earlier CSS rule already enforces), and the Tenants table's wide content
  scrolls inside its own `fi-ta-content-ctn` container rather than the page.

  **One real bug found and fixed**: `resources/views/components/ui/stepper.blade.php`
  (the 4-step progress indicator used by the tenant registration wizard) gave
  every step label `whitespace-nowrap` with no small-screen accommodation.
  Combined min-content width of the four labels ("Company Info", "Technical
  Setup", "Plan", "Payment") exceeded a 375px viewport, so the browser
  widened the page's whole layout viewport to fit (`Page.getLayoutMetrics`
  showed `layoutViewport.clientWidth: 434` against a `visualViewport` still
  pinned to 375) and scaled the entire page down to compensate — the
  textbook mobile-overflow failure mode, and visually obvious once screenshotted:
  headline copy and the trailing step labels ran off the right edge. Fixed
  by hiding the labels below `sm:` (`hidden sm:block`), leaving just the
  numbered circles + connector lines on phones — comfortably narrower than
  any viewport this needs to support. Confirmed both `Page.getLayoutMetrics()`
  parity (375 = 375) and a visual re-screenshot. User independently spotted
  the same page looked broken mid-session, before this was reported — see
  below, that instinct led to a second, far more serious bug on the same
  page.

- **CRITICAL — found outside this phase's original scope, while mobile-testing
  the registration wizard: the self-serve tenant signup flow was completely
  broken. Fixed, 2026-08-12.** Clicking "Continue" on step one silently did
  nothing — no console error, no server exception, no validation error,
  the Livewire request round-tripped successfully (`company_name` genuinely
  persisted server-side), and the wizard just never advanced. Two
  independent, both-required bugs stacked:

  1. **`Registration` (the wizard's `WizardComponent`) never became its own
     addressable Livewire component.** `resources/views/filament/admin/pages/register-tenant.blade.php`
     was a bare `@livewire('tenant-registration')` — the Filament page's
     *entire* render output was that one directive. Confirmed via the raw
     DOM (`wire:id`/`wire:snapshot` inspection) and the actual network
     request payload sent on "Continue": only two Livewire components ever
     existed on the page (the Filament page itself, and whichever step was
     current) — never a third for the wizard. `StepComponent::nextStep()`/
     `previousStep()`/`showStep()` (vendor, `spatie/laravel-livewire-wizard`)
     dispatch their transition event `->to($wizardClassName)`; with no live
     component registered under that name, the event had nowhere to land.
     Fixed by wrapping the directive in a `<div>` — enough to stop Livewire
     flattening the wizard's component boundary into the page's. Regression
     guard: `RegisterTenantTest::test_the_wizard_gets_its_own_component_boundary_separate_from_the_page`
     asserts on the rendered page's `wire:id` count directly, since the
     actual mechanical fact that broke can't be observed from a component
     unit test.
  2. **Independently, `wizardClassName` itself was wrong even once a target
     existed.** `WizardComponent::getCurrentStepState()` (vendor) hands every
     step component `'wizardClassName' => static::class` — the raw FQCN.
     `RegistrationWizardFeature` registers `Registration` under the short
     alias `tenant-registration` (`Livewire::addComponent`), matching every
     sibling step (`company-info`, `technical-setup`, `plan`) — so
     `->to($wizardClassName)` was targeting a name nothing is ever embedded
     under, vendor bug #1 above notwithstanding. Fixed with a
     `Registration::getCurrentStepState()` override resolving the real
     alias via `resolve('livewire.finder')->normalizeName(static::class)` —
     the exact pattern `Registration::stateToPersist()` already used for
     `Plan`'s own alias, see `.claude/rules/billing-checkout.md`'s note on
     `Payment`'s alias collision with Cashier's published view name for the
     precedent. Regression guard:
     `RegistrationRefreshTest::test_it_dispatches_step_transitions_to_the_wizards_registered_alias`
     uses Livewire's `assertDispatchedTo()` directly against the resolved
     alias.

  **Neither bug alone was sufficient to fix it — verified by testing each
  in isolation before combining them.** Both fixes required together.

  **How this stayed invisible**: `RegisterTenantTest.php` already carries
  two prior docblocked incidents on this exact page (a missing-layout crash,
  a missing `@livewireScripts` tag) — both times, the fix was "test HTTP
  200 isn't enough, prove the specific mechanism," and this is a third
  instance of the same lesson. Compounding it: `RegistrationRefreshTest.php`
  (and `PaymentTest`/`TechnicalSetupTest`/`RegistrationCheckoutHandoffTest`)
  all built their `Livewire::test(StepClass::class, [...])` mount params by
  hand, and all four hardcoded `'wizardClassName' => Registration::class`
  — reproducing the exact bug as if it were correct input, rather than
  resolving it through `livewire.finder` the way those same files already
  did for every *step's* alias one line above. Every isolated step-component
  test therefore exercised a wizard-targeting value that was wrong in
  exactly the way production was wrong, and asserted on session state or
  validation errors that never depended on the event actually landing
  anywhere — a vacuous pass, same family as `.claude/rules/testing.md`'s
  documented "risky test" trap (`assertDontSee` on an unregistered
  component). All four call sites now resolve the alias the same way their
  sibling steps already did. Full suite (558 passed, 7 skipped, 1
  pre-existing unrelated failure) and PHPStan clean on the touched files
  after the fix.

---

## Phase 8 — Cleanup + final audit

Dead `theme.css`, superseded color maps, and `->colors()` calls were already
gone by Phase 4/6; this phase's real find was the one item still
unaddressed: **components ignoring tokens (hardcoded instead)**.

**Raw semantic-color sweep — done.** Audited resources/views for
`bg-red-500`/`text-emerald-600`/etc. bypassing
`--color-{success,warning,danger,info}-*`/`--color-primary` and found 31
files, 100+ occurrences — a different, much larger drift than the
gray/neutral/stone sweep Phase 3 actually covered, despite Phase 0
predicting exactly this set of files ("billing/\* and the registration
wizard steps... that is the Phase 3 volume sweep" — it wasn't; Phase 3's
own scope was only the grey ramp and radius). Converted every genuinely
semantic occurrence to the matching token — warning banners, error text,
success/positive indicators, selection/accent states (→ `--color-primary`,
now meaningfully live since Phase 7 wired up `--pref-accent-hue`), one
`focus-within:ring-` focus-ring case Phase 7's own guard hadn't caught
(different pseudo-class than the `focus-visible:`/`focus:` pattern it
scanned for). Left three genuinely deliberate exceptions raw, each
justified in-file or in Phase 0 already: `ui/icon-tile`'s closed color
allowlist, card-network brand chips (Visa/Mastercard/etc.), and decorative
gradients/illustrations (hero icon badges, CTA buttons, a macOS-style
window-chrome mockup) that were never state indicators to begin with.
Guard: `DesignLanguageGuardTest::test_no_view_uses_a_raw_semantic_color_utility_instead_of_the_token`,
with an explicit, reasoned allowlist (`RAW_SEMANTIC_COLOR_ALLOWED_IN`)
matching the existing `ROUNDED_2XL_ALLOWED_IN` pattern.

Running that guard also surfaced a **9th raw-heroicon-string
`emptyStateIcon()`** the Phase 6 sweep had missed
(`AtRiskSubscriptionsTable.php`) — fixed the same way as the other 8.

**Duplicate tokens, Filament defaults leaking through, tokens that only
work on one surface** — not separately re-audited beyond what Phases 2–7
already verified per-item (each phase's own guard tests cover its slice);
no new findings surfaced doing the color sweep.

Full suite re-verified after this sweep: 471 passed, 7 skipped, 1
pre-existing failure (`RegisterTenantTest`); `pint` clean; `phpstan`
unchanged (8 pre-existing errors, none in touched files).

---

## Risks

| Risk | Mitigation |
|---|---|
| **Published-asset drift** — hosts hold their own `app.css` copy; token edits never reach them | Tokens live in package-owned `tokens.css`, imported from `vendor/`. Published file stays a 4-line entry point. Verified against `InstallNumerosisCommand`'s existing drift check. |
| **Host must build a Filament theme** (`->viteTheme()` needs a Vite entry the package cannot add) | Add to `printManualSteps()` and to the install command's verify-only checks, so a host missing it fails loudly rather than rendering stock Filament. |
| **`FilamentColor` memoisation** | Sidestepped entirely — CSS custom properties, no runtime `register()`. |
| **`nvade/branding` is thin-app-owned**, not a package dependency | Core reads tenant defaults through a contract; module absent = no-op, same shape as `ApplyDefaultBranding` today. |
| **57-view mechanical edit regresses** | Arch test failing on raw `gray-|neutral-|stone-` and stray radii. |
| **Test suite can't see this** — no browser tests, and view tests pass vacuously when Flux components render as literal text (`.claude/rules/testing.md`) | Assert on rendered token values and `data-*` attributes, not on component markup. Verify each regression test fails against pre-fix code. |
| Filament v5 internals shift on upgrade | Official extension points only; no descendant selectors into `fi-*` internals beyond the documented custom properties. |

---

## Gates

Each phase ends with: tests green (baseline is 9 known lock-wait failures — `.claude/rules/testing.md`), `pint`, `phpstan` diffed against `git stash`, and your approval before the next phase starts.

**Phases 0–4 are built** (`tokens.css`, `filament-theme.css`, `AppliesNumerosisPanelTheme`, guard tests) — uncommitted, sitting in the working tree.

**Phase 5 was found already built the wrong way** (per-user: `users.ui_preferences` migration, `Data\Ui\UiPreferences`, nine `Enums\Ui\*`, `ResolveUiPreferences`, a `numerosis::partials.theme` runtime `<style>` injector, and a rebuilt `settings/appearance` page) and has been torn out — see decision at top of this doc. Replaced with the consumer-only version: §5.1–5.4 above, plus a step 6 in `InstallNumerosisCommand::printManualSteps()` pointing hosts at overriding `--pref-*` tokens in their own published `app.css`. Full suite re-verified after the removal: 458 passed, 7 skipped, 1 pre-existing failure (`RegisterTenantTest`); `pint` clean; `phpstan` unchanged (8 pre-existing errors, none in touched files).

**Phase 6 (UX normalization) is done.** All 5 items resolved:
notification channel (built — `partials/toasts.blade.php` + `ui/toast.blade.php`),
empty-state vocabulary (icon references normalized to the `Heroicon` enum),
loading indicator (two redundant manual `wire:loading` sites removed —
`<x-filament::button>` already handles it, with a real spinner), and two
honest surveys that found nothing broken enough to justify new abstractions
(confirmation pattern; form-validation presentation — the one real gap
found there, Flux's hardcoded error color/icon, is vendor-owned and
documented as an accepted gap rather than patched). Each item verified
against the full suite (baseline: 466 passed, 7 skipped, 1 pre-existing
failure — `RegisterTenantTest`), `pint`, and `phpstan` (unchanged, 8
pre-existing errors) before commit.

**Phase 7 (responsive + accessibility) is done**, 2026-08-12 — the two
items requiring a real browser (keyboard nav/focus trapping, mobile
viewport widths) verified live against thin-app; the earlier
source-reading-only items (accent contrast, focus-visible ring, target
sizes, `prefers-reduced-motion`) were already done. One real mobile-overflow
bug found and fixed (the wizard stepper's non-wrapping labels), plus one
severity-unrelated critical bug found along the way and fixed immediately
rather than filed for later: the tenant registration wizard could not
advance past step one at all (two independent Livewire component-targeting
bugs, both required together — see this phase's own write-up above for the
full mechanism). Full suite: 558 passed, 7 skipped, 1 pre-existing failure
(`RegisterTenantTest::test_it_loads_the_livewire_javascript_runtime` —
confirmed via `git stash` to fail identically on a clean `main`, unrelated
to this session). `pint` clean. `phpstan` clean on every touched file.

**Phase 8 (cleanup + final audit) — nothing further identified.** No open
items remain in this plan.
