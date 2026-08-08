@props([
    'feature',
    'available' => true,
])

<li class="flex items-start group/feature">
    <div @class([
        'mr-3 mt-1 rounded-full p-0.5 transition-colors',
        'bg-success-bg group-hover/feature:bg-success-border' => $available,
        'bg-zinc-100 dark:bg-zinc-800' => ! $available,
    ])>
        @if($available)
            <flux:icon.check class="size-3.5 text-success-icon" variant="micro" />
        @else
            <flux:icon.minus class="size-3.5 text-zinc-400 dark:text-zinc-500" variant="micro" />
        @endif
    </div>
    <span @class([
        'leading-tight',
        'text-zinc-600 dark:text-zinc-300' => $available,
        'text-zinc-500 dark:text-zinc-500' => ! $available,
    ])>
        {{ $feature->name ?? $feature->slug }}
    </span>
</li>
