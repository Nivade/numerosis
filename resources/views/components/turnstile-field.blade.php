@props(['model' => 'turnstileResponse'])

@if (\Nvade\Numerosis\Features\Turnstile\TurnstileFeature::isEnabled())
    <x-turnstile wire:model="{{ $model }}" data-theme="auto" data-size="flexible" />
    @error($model)
        <p class="text-sm text-danger-text">{{ $message }}</p>
    @enderror
@endif
