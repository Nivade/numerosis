<div x-data="{ open: false }" class="relative">
    <div @mouseenter="open = true" @mouseleave="open = false">
        {{ $trigger }}
    </div>
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-1"
        class="absolute z-50 px-3 py-1.5 text-xs font-medium text-popover-foreground bg-popover border rounded-md shadow-sm"
        style="display: none; top: 100%; left: 50%; transform: translateX(-50%); margin-top: 0.5rem;"
    >
        {{ $slot }}
    </div>
</div>
