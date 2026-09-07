@if (\Nvade\Numerosis\Features\Turnstile\TurnstileFeature::available())
    <x-turnstile.scripts />

    <script>
        {{-- api.js auto-renders `.cf-turnstile` elements once, on its own script
             load/DOMContentLoaded. A `wire:navigate` swap replaces the body
             without reloading the script, so a widget landing on the page via
             SPA navigation never gets scanned and silently never renders. --}}
        document.addEventListener('livewire:navigated', () => {
            if (!window.turnstile) {
                return;
            }

            document.querySelectorAll('.cf-turnstile').forEach((el) => {
                if (el.children.length === 0) {
                    window.turnstile.render(el);
                }
            });
        });
    </script>
@endif
