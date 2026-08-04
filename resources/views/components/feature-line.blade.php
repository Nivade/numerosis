@props([
    'feature',
    'available' => true,
])

<li class="flex items-start group/feature">
    <div @class([
        'mr-3 mt-1 rounded-full p-0.5 transition-colors',
        'bg-green-100 dark:bg-green-950/30 group-hover/feature:bg-green-200 dark:group-hover/feature:bg-green-800/50' => $available,
        'bg-gray-100 dark:bg-zinc-800' => ! $available,
    ])>
        @if($available)
            <flux:icon.check class="size-3.5 text-green-600 dark:text-green-400" variant="micro" />
        @else
            <flux:icon.minus class="size-3.5 text-gray-400 dark:text-gray-500" variant="micro" />
        @endif
    </div>
    <span @class([
        'leading-tight',
        'text-gray-700 dark:text-gray-300' => $available,
        'text-gray-500 dark:text-zinc-500' => ! $available,
    ])>
        {{ $feature->name ?? $feature->slug }}
    </span>
</li>
