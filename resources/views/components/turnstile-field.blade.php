{{--
    `model` is opt-in. The vendor component branches on `wire:model`, and its
    `@if ($model)` branch emits `@this.set(...)`, which Blade compiles to
    `$_instance->getId()`. That is undefined outside a Livewire render, so
    defaulting a model here 500s every plain Blade form that uses this
    component ("Undefined variable $_instance"), the guest auth screens
    included.

    Livewire callers (Invitations\Accept) pass `model="…"` and get the
    reactive widget. Plain forms pass nothing and get the stock widget, whose
    own hidden input is named `cf-turnstile-response`.
--}}
@props(['model' => null])

@if (\Nvade\Numerosis\Features\Turnstile\TurnstileFeature::available())
    @if ($model)
        <x-turnstile wire:model="{{ $model }}" data-theme="auto" data-size="flexible" />
    @else
        <x-turnstile data-theme="auto" data-size="flexible" />
    @endif

    @error($model ?? 'cf-turnstile-response')
        <p class="text-sm text-danger-text">{{ $message }}</p>
    @enderror
@endif
