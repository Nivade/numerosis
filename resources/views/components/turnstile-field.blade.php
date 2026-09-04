{{--
    `model` is opt-in, not defaulted. The vendor component branches on
    `wire:model`, and its `@if ($model)` branch emits `@this.set(...)`, which
    Blade compiles to `$_instance->getId()` — undefined outside a Livewire
    render, so passing a model unconditionally made this component fatal on
    every plain Blade form ("Undefined variable $_instance"). Phase 4 of
    `.claude/plans/archive/humming-nibbling-flame.md` converted the four guest auth
    screens away from Livewire, which is what turned that into a 500 on GET
    /login for anyone with `TurnstileFeature` on — the shipped default.

    Livewire callers (Invitations\Accept) pass `model="…"` and get the
    reactive widget. Plain forms pass nothing and get the stock widget, whose
    own hidden input is named `cf-turnstile-response`.
--}}
@props(['model' => null])

@if (\Nvade\Numerosis\Features\Turnstile\TurnstileFeature::isEnabled())
    @if ($model)
        <x-turnstile wire:model="{{ $model }}" data-theme="auto" data-size="flexible" />
    @else
        <x-turnstile data-theme="auto" data-size="flexible" />
    @endif

    @error($model ?? 'cf-turnstile-response')
        <p class="text-sm text-danger-text">{{ $message }}</p>
    @enderror
@endif
