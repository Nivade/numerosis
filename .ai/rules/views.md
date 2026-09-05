---
paths:
  - 'resources/views/**'
---
# Views

## Never pass wire:model to x-turnstile from a plain Blade form
`ryangjchandler/laravel-cloudflare-turnstile`'s component branches on `wire:model` and its truthy branch emits `@this.set(...)`, which Blade compiles to `$_instance->getId()`. Outside a Livewire render that is "Undefined variable $_instance" — a 500, not a degraded widget. `<x-numerosis::turnstile-field />` used to default `model` to `'turnstileResponse'`, so every guest auth screen Phase 4 converted from Livewire to plain Blade returned 500 on GET whenever `TurnstileFeature` was on (the shipped default). `model` is now opt-in: Livewire callers pass `model="…"`, plain forms pass nothing. Separately unresolved: the plain forms render the widget but nothing validates `cf-turnstile-response`, so Turnstile is decorative on Fortify's routes.
