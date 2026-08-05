@props(['model' => 'turnstileResponse'])

@if (\Nvade\Numerosis\Features\Turnstile\TurnstileFeature::isEnabled())
    <x-turnstile wire:model="{{ $model }}" data-theme="auto" data-size="flexible" />
    @error($model)
        <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror
@endif
