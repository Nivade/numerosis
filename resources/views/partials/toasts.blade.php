@use(\Nvade\Numerosis\Enums\FlashKey)
@use(\Nvade\NumerosisUi\Enums\Severity)

@php
    /**
     * The one notification channel (design-system-unification Phase 6):
     * every surface pushes through this same
     * `notify` browser event instead of each page rolling its own inline
     * flash box the way the registration wizard used to
     * (`<x-numerosis::ui.alert closable />`, read directly off session()).
     *
     * `FlashKey::Status` carries the current `[Severity, message]` pair;
     * the four scalar keys below are the pre-convergence shape, kept for one
     * cycle. Same palette as ui/alert (tokens.css's semantic colors) — this
     * is the floating counterpart of that inline component, not a second
     * design.
     */
    $legacyFlash = [];

    $statusFlash = session(FlashKey::Status->value);

    if (is_array($statusFlash) && ($statusFlash[0] ?? null) instanceof Severity) {
        [$severity, $message] = $statusFlash;
        $legacyFlash[] = ['type' => $severity->value, 'message' => $message];
    }

    foreach (['success', 'error', 'warning', 'info'] as $key) {
        if (session($key)) {
            $legacyFlash[] = ['type' => $key, 'message' => session($key)];
        }
    }

    if (session('message')) {
        $legacyFlash[] = ['type' => 'info', 'message' => session('message')];
    }
@endphp
<div
    x-data="{
        toasts: [],
        push(toast) {
            toast.id = Date.now() + Math.random();
            this.toasts.push(toast);
            setTimeout(() => this.dismiss(toast.id), toast.duration ?? 5000);
        },
        dismiss(id) {
            this.toasts = this.toasts.filter((toast) => toast.id !== id);
        },
    }"
    x-init="@js($legacyFlash).forEach((toast) => push(toast))"
    x-on:notify.window="push($event.detail)"
    class="fixed inset-x-0 top-4 z-(--z-toast) flex flex-col items-center gap-2 px-4 sm:items-end sm:px-6"
    aria-live="polite"
    aria-atomic="true"
>
    <template x-for="toast in toasts" :key="toast.id">
        <x-numerosis::ui.toast x-toast="toast" />
    </template>
</div>
