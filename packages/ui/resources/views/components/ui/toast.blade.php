@php
    /**
     * The floating counterpart of ui/alert — same four types, same
     * semantic-token palette, but populated entirely client-side from the
     * `toast` object `partials/toasts.blade.php`'s `x-for` binds into scope
     * (`{type, message, title}`). No Blade props: this renders once into
     * static markup with Alpine bindings, since every instance is a runtime
     * array entry, not a server-known value.
     *
     * `<flux:icon>` resolves which SVG to inline at *render* time from its
     * `name` prop — it cannot take an Alpine-bound name the way a plain
     * `<svg>` reference could, because Flux compiles a distinct
     * sub-component per icon rather than switching on a runtime string. So
     * this renders all four icons and toggles visibility with `x-show`,
     * instead of binding one icon's `name` dynamically.
     *
     * Every other dynamic attribute below uses the explicit `x-bind:` form
     * rather than Blade's `:attr="…"` shorthand — on a Blade component tag,
     * a leading `:` is Blade's own dynamic-prop syntax and gets evaluated as
     * PHP, not left for Alpine.
     */
    $palette = <<<'JS'
        {
            success: { surface: 'bg-success-bg border-success-border', text: 'text-success-text', icon: 'text-success-icon' },
            warning: { surface: 'bg-warning-bg border-warning-border', text: 'text-warning-text', icon: 'text-warning-icon' },
            error: { surface: 'bg-danger-bg border-danger-border', text: 'text-danger-text', icon: 'text-danger-icon' },
            info: { surface: 'bg-info-bg border-info-border', text: 'text-info-text', icon: 'text-info-icon' },
        }[toast.type ?? 'info']
    JS;
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
