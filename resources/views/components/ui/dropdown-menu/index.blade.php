<div x-data="{ open: false }" @click.outside="open = false" class="relative">
    <div @click="open = !open">
        {{ $trigger }}
    </div>

    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="transform opacity-0 scale-95"
        x-transition:enter-end="transform opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="transform opacity-100 scale-100"
        x-transition:leave-end="transform opacity-0 scale-95"
        class="absolute right-0 z-50 mt-2 w-56 origin-top-right rounded-md border bg-popover p-1 text-popover-foreground shadow-md outline-none"
        style="display: none;"
    >
        {{ $slot }}
    </div>
</div>
