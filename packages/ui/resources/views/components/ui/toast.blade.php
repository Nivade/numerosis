@use(\Nvade\NumerosisUi\Enums\Severity)

@php
    /**
     * The floating counterpart of ui/alert, populated client-side from the
     * `toast` object `partials/toasts.blade.php` binds into scope.
     *
     * `<flux:icon>` resolves its SVG at render time and cannot take an
     * Alpine-bound name, so all four render and `x-show` picks one.
     */
    $paletteEntries = collect(Severity::cases())
        ->map(fn (Severity $severity) => sprintf(
            "%s: { surface: '%s', text: '%s', icon: '%s' }",
            $severity->value,
            $severity->surfaceClasses(),
            $severity->textClasses(),
            $severity->iconColorClasses(),
        ))
        ->implode(",\n            ");

    $palette = "{\n            {$paletteEntries},\n        }[toast.type ?? 'info']";
@endphp
<div
    x-show="true"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-2 sm:translate-y-0 sm:translate-x-2"
    x-transition:enter-end="opacity-100 translate-y-0 sm:translate-x-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    x-data="{ style: {{ $palette }} }"
    role="alert"
    class="rounded-xl border p-4 shadow-lg w-full sm:w-96"
    x-bind:class="style.surface"
>
    <div class="flex items-start gap-3">
        <flux:icon name="check-circle" x-show="toast.type === 'success'" x-bind:class="style.icon" class="size-5 shrink-0 mt-0.5" />
        <flux:icon name="exclamation-triangle" x-show="toast.type === 'warning'" x-bind:class="style.icon" class="size-5 shrink-0 mt-0.5" />
        <flux:icon name="x-circle" x-show="toast.type === 'error'" x-bind:class="style.icon" class="size-5 shrink-0 mt-0.5" />
        <flux:icon name="information-circle" x-show="!toast.type || toast.type === 'info'" x-bind:class="style.icon" class="size-5 shrink-0 mt-0.5" />

        <div class="flex-1" x-bind:class="style.text">
            <p x-show="toast.title" x-text="toast.title" class="text-sm font-semibold mb-1"></p>
            <div class="text-sm" x-text="toast.message"></div>
        </div>

        <button
            type="button"
            x-on:click="dismiss(toast.id)"
            x-bind:class="style.icon"
            class="-m-1.5 shrink-0 rounded-lg p-1.5 hover:bg-black/5 dark:hover:bg-white/5 focus-ring"
        >
            <span class="sr-only">{{ __('Dismiss') }}</span>
            <flux:icon name="x-mark" class="size-5" />
        </button>
    </div>
</div>
