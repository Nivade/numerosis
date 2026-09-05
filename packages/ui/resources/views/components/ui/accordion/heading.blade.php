@props(['icon' => null])

<button
    type="button"
    @click="toggle"
    {{ $attributes->class([
        'flex w-full items-center rounded-md px-2 py-1.5 text-start text-sm font-medium transition-colors focus:outline-hidden',
        'text-zinc-800 hover:bg-zinc-50 dark:text-white dark:hover:bg-zinc-600',
    ]) }}
>
    @if ($icon)
        <flux:icon :$icon variant="mini" class="me-2 text-zinc-400 dark:text-white/60" />
    @else
        <div class="hidden w-7 [[data-flux-menu]:has(>[data-flux-menu-item-has-icon])_&]:block"></div>
    @endif

    <span class="flex-1">
        {{ $slot }}
    </span>

    <flux:icon icon="chevron-down" variant="mini" class="ms-auto text-zinc-400 transition-transform duration-200" x-bind:class="{ 'rotate-180': isOpen }" />
</button>
