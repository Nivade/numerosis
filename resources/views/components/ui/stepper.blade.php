@props([
    'steps' => [],
    'current' => 1,
])

<div {{ $attributes->class(['flex justify-center']) }}>
    <div class="flex w-full max-w-2xl items-center px-4">
        @foreach ($steps as $index => $step)
            @php
                $stepNumber = $index + 1;
                $isActive = $stepNumber === $current;
                $isCompleted = $stepNumber < $current;
            @endphp

            <div class="flex flex-col items-center relative z-10 group">
                <div @class([
                    'flex size-10 items-center justify-center rounded-full font-bold shadow-sm transition-all duration-500',
                    'bg-linear-to-r from-blue-600 to-indigo-600 text-white scale-110 shadow-blue-500/20 ring-4 ring-blue-500/10' => $isActive,
                    'bg-green-500 text-white' => $isCompleted,
                    'bg-zinc-100 text-zinc-400 dark:bg-zinc-800 dark:text-zinc-500' => !$isActive && !$isCompleted,
                ])>
                    @if ($isCompleted)
                        <flux:icon.check variant="micro" class="size-5" />
                    @else
                        {{ $stepNumber }}
                    @endif
                </div>
                <span @class([
                    'mt-3 text-xs font-semibold uppercase tracking-wider whitespace-nowrap transition-colors duration-500',
                    'text-blue-600 dark:text-blue-400' => $isActive,
                    'text-green-600 dark:text-green-500' => $isCompleted,
                    'text-zinc-400 dark:text-zinc-600' => !$isActive && !$isCompleted,
                ])>
                    {{ $step }}
                </span>
            </div>

            @if (!$loop->last)
                <div class="mx-0 -mt-7 h-[2px] flex-1 bg-zinc-100 dark:bg-zinc-800 relative">
                    <div @class([
                        'absolute inset-0 transition-all duration-1000 ease-in-out',
                        'bg-linear-to-r from-green-500 to-green-500 w-full' => $isCompleted,
                        'bg-linear-to-r from-green-500 to-blue-600 w-full' => $isActive && $isCompleted, // Not possible with this logic but for future
                        'w-0' => !$isCompleted,
                    ])></div>
                </div>
            @endif
        @endforeach
    </div>
</div>
