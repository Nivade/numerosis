@props([
    'type' => 'info',
    'title' => null,
    'message' => null,
    'closable' => false,
    'session' => null,
])

@php
    // The canonical callout. `ui/info-box` delegates here rather than keeping
    // the second copy of this palette it used to carry.
    //
    // Same semantic tokens as ui/badge (resources/css/tokens.css) — one map,
    // two consumers, so the two can no longer disagree on tint depth the way
    // they used to (badge at 100, alert at 50; alert's step won as `-bg`).
    //
    // The classes stay spelled out here because Tailwind only generates what
    // it can scan in a `.blade.php` file; a shared PHP palette class under
    // `src/` would not be scanned. Every value below is a token utility, not
    // a literal colour, so there is nothing left here for the two files to
    // drift on independently.
    $palette = [
        'success' => [
            'surface' => 'bg-success-bg border-success-border',
            'text' => 'text-success-text',
            'icon' => 'text-success-icon',
            'button' => 'text-success-icon hover:text-success-text',
            'glyph' => 'check-circle',
        ],
        'warning' => [
            'surface' => 'bg-warning-bg border-warning-border',
            'text' => 'text-warning-text',
            'icon' => 'text-warning-icon',
            'button' => 'text-warning-icon hover:text-warning-text',
            'glyph' => 'exclamation-triangle',
        ],
        'error' => [
            'surface' => 'bg-danger-bg border-danger-border',
            'text' => 'text-danger-text',
            'icon' => 'text-danger-icon',
            'button' => 'text-danger-icon hover:text-danger-text',
            'glyph' => 'x-circle',
        ],
        'info' => [
            'surface' => 'bg-info-bg border-info-border',
            'text' => 'text-info-text',
            'icon' => 'text-info-icon',
            'button' => 'text-info-icon hover:text-info-text',
            'glyph' => 'information-circle',
        ],
    ];

    // `danger` is what ui/info-box called the red variant; both names resolve
    // so neither caller has to change.
    $type = $type === 'danger' ? 'error' : $type;

    $displayMessage = $message;

    if ($session && session($session)) {
        $displayMessage = session($session);
    }

    if (! $displayMessage) {
        foreach (['success', 'error', 'warning', 'info', 'message'] as $key) {
            if (session($key)) {
                $displayMessage = session($key);
                $type = $key === 'message' ? 'info' : $key;

                break;
            }
        }
    }

    $style = $palette[$type] ?? $palette['info'];
@endphp

@if ($displayMessage || ! $slot->isEmpty())
    <div
        x-data="{ show: true }"
        x-show="show"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        role="alert"
        {{ $attributes->class(['rounded-xl border p-4', $style['surface']]) }}
    >
        <div class="flex items-start gap-3">
            <flux:icon :name="$style['glyph']" class="size-5 shrink-0 mt-0.5 {{ $style['icon'] }}" />

            <div class="flex-1 {{ $style['text'] }}">
                @if ($title)
                    <p class="text-sm font-semibold mb-1">{{ $title }}</p>
                @endif

                <div class="text-sm">
                    {{ $slot->isEmpty() ? $displayMessage : $slot }}
                </div>
            </div>

            @if ($closable)
                <button
                    type="button"
                    @click="show = false"
                    class="-m-1.5 shrink-0 rounded-lg p-1.5 {{ $style['button'] }} hover:bg-black/5 dark:hover:bg-white/5 focus-ring"
                >
                    <span class="sr-only">{{ __('Dismiss') }}</span>
                    <flux:icon name="x-mark" class="size-5" />
                </button>
            @endif
        </div>
    </div>
@endif
