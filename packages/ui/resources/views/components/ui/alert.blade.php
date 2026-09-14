@use(\Nvade\NumerosisUi\Enums\Severity)

@props([
    'type' => 'info',
    'title' => null,
    'message' => null,
    'closable' => false,
    'session' => null,
])

@php
    // The canonical callout, which `ui/info-box` delegates to. `Severity` is
    // the one source for the token map.
    $severity = Severity::fromAlias($type) ?? Severity::Info;

    $displayMessage = $message;

    if ($session && session($session)) {
        $displayMessage = session($session);
    }

    // `status` is `FlashKey::Status->value` — the literal, not the core enum
    // (packages/ui may not name a core symbol), holding a `[Severity,
    // message]` pair since the flash convergence.
    $statusFlash = session('status');

    if (! $displayMessage && is_array($statusFlash) && $statusFlash[0] instanceof Severity) {
        [$severity, $displayMessage] = $statusFlash;
    }

    // Legacy scan, kept for one cycle: pre-convergence call sites still flash
    // these four scalar keys directly rather than the `[Severity, message]`
    // pair.
    if (! $displayMessage) {
        foreach (['success', 'error', 'warning', 'info', 'message'] as $key) {
            if (session($key)) {
                $displayMessage = session($key);
                $severity = Severity::fromAlias($key) ?? Severity::Info;

                break;
            }
        }
    }
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
        {{ $attributes->class(['rounded-xl border p-4', $severity->surfaceClasses()]) }}
    >
        <div class="flex items-start gap-3">
            <flux:icon :name="$severity->icon()" class="size-5 shrink-0 mt-0.5 {{ $severity->iconColorClasses() }}" />

            <div class="flex-1 {{ $severity->textClasses() }}">
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
                    class="-m-1.5 shrink-0 rounded-lg p-1.5 {{ $severity->buttonClasses() }} hover:bg-black/5 dark:hover:bg-white/5 focus-ring"
                >
                    <span class="sr-only">{{ __('Dismiss') }}</span>
                    <flux:icon name="x-mark" class="size-5" />
                </button>
            @endif
        </div>
    </div>
@endif
