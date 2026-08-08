# Design System Unification — main app + 2 Filament panels

**Status:** Phases 0–4 built (uncommitted); old per-user Phase 5 scaffolding removed and replaced with consumer-level override docs; Phases 6–8 not started
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
- **One loading indicator** shared by `wire:loading` and Filament.
- **One confirmation pattern** and one action vocabulary — a button that says "Publish" produces a toast that says "Published", in all three surfaces.
- **One form-validation presentation** — error color, position, and icon from the semantic tokens.

---

## Phase 7 — Responsive + accessibility

- Contrast checked at every accent × theme combination a host might set. Accent ramps generated so `--color-primary-contrast` always clears WCAG AA against `--color-primary`; document the constraint so a host picking a custom `--pref-accent-hue` knows the bound.
- Visible `:focus-visible` ring on every interactive element, token-driven, in all three surfaces.
- Keyboard nav through Filament sidebar, tables, modals; tab order and focus trapping.
- Target sizes ≥ 44px at every density — **compact density must not shrink hit targets below the floor.**
- `prefers-reduced-motion` drives `--duration-*` to `1ms` globally (currently declared only in the dead theme.css).
- Filament panels verified at mobile widths independently — the tenant panel is `->spa()` at `path('/')` and is a primary mobile surface, not a shrunk desktop layout.

---

## Phase 8 — Cleanup + final audit

Delete: dead `theme.css`, superseded per-component color maps, duplicate radius/spacing literals, `->colors()` calls.

Final sweep for: hardcoded theme values, duplicate tokens, **Filament defaults leaking through**, components ignoring tokens (hardcoded instead), and any token that only works in one of the three surfaces.

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

**Next: Phase 6 (UX normalization)** — nothing built yet. Nothing in this repo is committed; confirm with the user before committing.
