@props([
    'icon' => null,
    'title',
    'description' => null,
])

<div {{ $attributes->merge(['class' => 'border-b border-zinc-200 dark:border-zinc-700 pb-6']) }}>
    <div class="flex items-start space-x-4">
        @if($icon)
            <div class="shrink-0">
                <div class="w-12 h-12 rounded-full bg-linear-to-r from-blue-500 to-purple-600 flex items-center justify-center shadow-lg">
                    <flux:icon :name="$icon" class="h-6 w-6 text-white" />
                </div>
            </div>
        @endif
        <div class="flex-1 min-w-0">
            <h3 class="text-2xl font-bold text-zinc-900 dark:text-white">
                {{ $title }}
            </h3>
            @if($description)
                <p class="text-base text-zinc-600 dark:text-zinc-400 mt-2 leading-relaxed">
                    {{ $description }}
                </p>
            @endif
        </div>
    </div>
</div>
