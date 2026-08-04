@props([
    'icon' => null,
    'title' => null,
    'description' => null,
    'action' => null,
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center text-center p-10']) }}>
    @if($icon)
        <flux:icon :name="$icon" class="w-10 h-10 text-zinc-400 dark:text-zinc-500 mb-3" />
    @endif

    @if($title)
        <p class="text-gray-700 dark:text-gray-200 font-medium">{{ $title }}</p>
    @endif

    @if($description)
        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1 max-w-md">{{ $description }}</p>
    @endif

    @if($action)
        <div class="mt-4">
            {{ $action }}
        </div>
    @endif

    {{ $slot }}
</div>
