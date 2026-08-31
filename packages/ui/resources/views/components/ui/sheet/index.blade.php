<div x-data="{ open: false }" @keydown.escape.window="open = false" class="relative">
    <div @click="open = true">
        {{ $trigger }}
    </div>

    <template x-teleport="body">
        <div x-show="open" class="fixed inset-0 z-50 flex">
            <!-- Overlay -->
            <div
                x-show="open"
                x-transition:enter="transition-opacity ease-linear duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition-opacity ease-linear duration-300"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                @click="open = false"
                class="fixed inset-0 bg-black/80"
                aria-hidden="true"
            ></div>

            <!-- Content -->
            <div
                x-show="open"
                x-transition:enter="transition ease-in-out duration-300 transform"
                x-transition:enter-start="-translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition ease-in-out duration-300 transform"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="-translate-x-full"
                class="relative flex w-full max-w-xs flex-1 flex-col bg-background p-6 shadow-xl outline-none"
            >
                <div class="absolute right-4 top-4">
                    <flux:button variant="ghost" icon-trailing="x-mark" @click="open = false">
                        <span class="sr-only">Close</span>
                    </flux:button>
                </div>
                <div class="mt-8 overflow-y-auto">
                    {{ $slot }}
                </div>
            </div>
        </div>
    </template>
</div>
