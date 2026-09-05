---
paths:
  - 'resources/views/**'
---
# Views

## Never pass wire:model to x-turnstile from a plain Blade form
`ryangjchandler/laravel-cloudflare-turnstile`'s component branches on `wire:model` and its truthy branch emits `@this.set(...)`, which Blade compiles to `$_instance->getId()`. Outside a Livewire render that is "Undefined variable $_instance" — a 500, not a degraded widget. `<x-numerosis::turnstile-field />` used to default `model` to `'turnstileResponse'`, so every guest auth screen Phase 4 converted from Livewire to plain Blade returned 500 on GET whenever `TurnstileFeature` was on (the shipped default). `model` is now opt-in: Livewire callers pass `model="…"`, plain forms pass nothing. Separately unresolved: the plain forms render the widget but nothing validates `cf-turnstile-response`, so Turnstile is decorative on Fortify's routes.

## Core's Livewire namespaces are `numerosis-layouts`/`numerosis-pages`, and `livewire.component_layout` is not core's to set

Renamed 2026-09-05. `NumerosisServiceProvider::registerLivewireComponentNamespaces()` used to claim the generic `layouts`/`pages` keys whenever they were null *or* still equal to `resource_path("views/{$namespace}")` — i.e. precisely when an existing host was using its own. A Livewire namespace maps one prefix to exactly one directory, so that is not a merge: the host's layouts become unreachable.

The rename has one non-obvious consequence. `livewire.component_layout` defaults to `'layouts::app'` in Livewire's own shipped config, and core used to satisfy it by accident through the repointed `layouts` namespace. Core must not backfill that key — a host leaving the stock value and shipping its own `resources/views/layouts/app.blade.php` is served correctly by it. Instead **every core full-page Livewire component carries its own `#[Layout('numerosis-layouts::app')]`**: `Livewire\Settings\{Profile,Password,ConnectedAccounts}`, `Livewire\Billing\Checkout`, `Livewire\Tenant\Registration`. A new full-page component without the attribute renders against whatever the host configured, and fails with `No hint path defined for [layouts]` on a host that configured nothing — a 500 at render time, green in every unit test.
